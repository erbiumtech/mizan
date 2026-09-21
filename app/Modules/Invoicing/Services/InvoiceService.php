<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\CurrencyRevaluationService;
use App\Modules\Accounting\Services\DeferralService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Support\Money;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\CustomerCredit;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceEvent;
use App\Modules\Invoicing\Models\InvoiceLine;
use App\Modules\Invoicing\Models\TaxRate;
use App\Support\ModuleMap;
use App\Support\TenantTransaction;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Issue, pay, and void invoices. Issuing posts one balanced journal
 * entry per invoice; product lines drive stock movements (sales consume
 * lots and book COGS in the same entry, purchases create lots).
 */
class InvoiceService
{
    /**
     * Which entry lines are in the *document's* currency.
     *
     * A sale in euros has a receivable, revenue and tax in euros — but the cost of goods
     * sold leg is not: it comes from the valuation engine, which holds inventory at what
     * was actually paid for it, in the base currency. Translating that too would restate
     * the cost of the stock at the rate the customer happened to be billed at.
     *
     * The control line is the receivable or the payable: the one carrying the invoice
     * total, and the one that has to translate to exactly what the document is worth.
     */
    private const FX_CONTROL = 'control';

    private const FX_LINE = 'line';

    public function __construct(
        private JournalEntryService $journalEntryService,
        private InventoryValuationService $valuation,
    ) {}

    /**
     * Settle the rate an invoice is posted at, once.
     *
     * A rate given on the invoice wins — an agreed rate is a term of the deal. Otherwise
     * it is the rate in force on the invoice date, and it is written down, because a rate
     * recorded later for that day must not silently restate an issued invoice.
     */
    protected function fixRate(Invoice $invoice): Invoice
    {
        if (! $invoice->isForeignCurrency()) {
            return $invoice;
        }

        if ($invoice->exchange_rate) {
            return $invoice;
        }

        $converted = Money::toBase(
            1,
            $invoice->currencyCode(),
            $invoice->invoice_date->toDateString(),
        );

        $invoice->update(['exchange_rate' => $converted['rate']]);

        return $invoice;
    }

    /**
     * The document's lines in the base currency, with the foreign amounts alongside.
     *
     * Each line keeps what it was billed as and gains what it is worth; the base amounts
     * are what every report reads. The receivable translates to exactly the invoice's
     * base total, and any rounding left over by translating the parts separately is put
     * on the last revenue or expense line — a cent of revenue nobody will read, rather
     * than a receivable that disagrees with the invoice it came from, which the
     * settlement arithmetic later depends on.
     *
     * @param  array<int, array<string, mixed>>  $entryLines
     * @return array<int, array<string, mixed>>
     */
    protected function translateDocument(Invoice $invoice, array $entryLines): array
    {
        if (! $invoice->isForeignCurrency()) {
            return array_map(fn (array $line): array => Arr::except($line, '_fx'), $entryLines);
        }

        $rate = $invoice->rate();
        $code = $invoice->currencyCode();
        $lastLine = null;

        foreach ($entryLines as $index => $line) {
            if (! isset($line['_fx'])) {
                continue;
            }

            $debit = (float) ($line['debit_amount'] ?? 0);
            $credit = (float) ($line['credit_amount'] ?? 0);

            $entryLines[$index] = Arr::except($line, '_fx') + [
                'currency_code' => $code,
                'rate' => $rate,
                'foreign_debit_amount' => $debit ?: null,
                'foreign_credit_amount' => $credit ?: null,
            ];

            $entryLines[$index]['debit_amount'] = $debit ? round($debit * $rate, 2) : 0;
            $entryLines[$index]['credit_amount'] = $credit ? round($credit * $rate, 2) : 0;

            if ($line['_fx'] === self::FX_CONTROL) {
                // Exactly the invoice's base total, not a translation of a translation.
                $entryLines[$index][$debit ? 'debit_amount' : 'credit_amount'] = $invoice->baseTotal();

                continue;
            }

            $lastLine = $index;
        }

        return $this->absorbRounding($entryLines, $lastLine);
    }

    /**
     * Put whatever translating the parts separately left over onto one line.
     *
     * round(a × r) + round(b × r) is not round((a + b) × r), so an entry translated line
     * by line can be a cent out. Somebody has to hold that cent, and a revenue line is
     * the only place it does no harm.
     *
     * @param  array<int, array<string, mixed>>  $entryLines
     * @return array<int, array<string, mixed>>
     */
    protected function absorbRounding(array $entryLines, ?int $index): array
    {
        if ($index === null) {
            return $entryLines;
        }

        $imbalance = round(array_sum(array_map(
            fn (array $line): float => (float) ($line['debit_amount'] ?? 0) - (float) ($line['credit_amount'] ?? 0),
            $entryLines,
        )), 2);

        if (abs($imbalance) < 0.005) {
            return $entryLines;
        }

        // Excess debits are cleared by crediting more, and vice versa — whichever side
        // this line is already on.
        ($entryLines[$index]['credit_amount'] ?? 0) > 0
            ? $entryLines[$index]['credit_amount'] = round($entryLines[$index]['credit_amount'] + $imbalance, 2)
            : $entryLines[$index]['debit_amount'] = round($entryLines[$index]['debit_amount'] - $imbalance, 2);

        return $entryLines;
    }

    public function issue(Invoice $invoice): Invoice
    {
        if (! $invoice->isDraft()) {
            throw new InvalidArgumentException("Only draft invoices can be issued (invoice is {$invoice->status}).");
        }

        // Before the totals are checked, so an invoice built from rates is issued
        // with the tax those rates give rather than whatever was last saved.
        //
        // A credit note is totalled differently, and the difference is the point of one. Its
        // line tax was taken from the invoice it reverses — the tax actually charged and
        // posted — so re-deriving it from the rate table would credit today's rate against
        // yesterday's posting and leave the tax account permanently out by the difference
        // whenever a rate has been edited in between. The whole value of a correction is that
        // it equals what it corrects.
        //
        // The document totals are still re-derived, from the lines' own figures, because the
        // draft is editable: somebody trimming a full credit down to the two lines that were
        // wrong must not have to make the header agree by hand.
        // `isAdjustment()` rather than `isCreditNote()`: a debit note inherits its bill's tax for exactly the
        // same reason — Phase 5.
        $invoice->isAdjustment()
            ? $this->totalFromLines($invoice)
            : $this->applyTaxes($invoice);

        $lines = $invoice->lines()->with(['product', 'taxRate'])->get();

        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Invoice has no lines.');
        }

        $this->validateTotals($invoice, $lines);

        // Before anything is posted: an invoice in another currency is issued at one
        // rate, and that rate is what ties the document to the ledger from here on.
        $this->fixRate($invoice);

        $this->assertWithinCredit($invoice);

        return TenantTransaction::run(function () use ($invoice, $lines) {
            $entryLines = $this->translateDocument($invoice, match ($invoice->kind) {
                Invoice::KIND_SALE => $this->saleEntryLines($invoice, $lines),
                Invoice::KIND_CREDIT_NOTE => $this->creditNoteEntryLines($invoice, $lines),
                Invoice::KIND_DEBIT_NOTE => $this->debitNoteEntryLines($invoice, $lines),
                default => $this->purchaseEntryLines($invoice, $lines),
            });

            $entry = $this->postSystemEntry(
                $invoice->invoice_date->toDateString(),
                "{$invoice->invoice_number} — {$invoice->contact->name}",
                $entryLines,
                $invoice,
            );

            // A credit note moves no stock, and that is a decision rather than an omission.
            // Crediting a customer and taking goods back are two different events that
            // usually but not always happen together: a credit for a damaged consignment,
            // an overcharge, or a service that was billed twice returns nothing to the
            // shelf. Assuming the goods came back would put quantity into inventory that
            // is not there and hand the valuation engine lots at a made-up cost — wrong in
            // a way nobody notices until a stock count. If goods genuinely return, that is
            // a stock movement somebody records, and it says so.
            //
            // **A debit note moves no stock either, and the argument is the stronger one there** — Phase 5.
            // A bill's own issue took goods *in* at a cost; a debit note that reversed the movement would
            // take them out at the valuation engine's current cost, which after any other receipt is not the
            // cost they came in at. Returning goods to a supplier is a stock movement somebody records
            // against the shelf they came off.
            if (! $invoice->isAdjustment()) {
                foreach ($lines as $line) {
                    if ($line->product_id) {
                        $this->recordMovement($invoice, $line, $entry);
                    }
                }
            }

            $invoice->update([
                'status' => Invoice::STATUS_ISSUED,
                'journal_entry_id' => $entry->id,
                'fiscal_year_id' => $invoice->fiscal_year_id
                    ?? FiscalYear::where('is_active', true)->value('id'),
            ]);

            InvoiceEvent::record(
                $invoice,
                InvoiceEvent::ISSUED,
                "Issued and posted as {$entry->entry_number}",
                (float) $invoice->total,
            );

            return $invoice;
        });
    }

    /**
     * Money received against an invoice, and the exchange difference it realises.
     *
     * `$amount` is in the invoice's currency, because that is what the client pays and
     * what the invoice says they owe. On a base-currency invoice nothing below changes:
     * the rate is 1, the difference is zero, and no FX line is written.
     *
     * On a foreign invoice, three amounts are in play and only two of them agree. The
     * receivable was booked at the invoice's rate; the money arrived at the rate on the
     * day it arrived. That difference is real — it is the gain or loss the company
     * actually made by being paid later than it billed — and it is recognised here, in
     * full, as realised. `$rate` overrides the table for the case that matters most: a
     * bank advice saying what actually landed is a fact, and the rate table is only an
     * estimate of it.
     */
    /**
     * One receipt settling several invoices — `docs/erpnext-gap-plan.md` Phase 2, item 2.
     *
     * ERPNext's Payment Entry is a document with a references table: one payment, many invoices, and
     * whatever is left over sits as an advance. The lazy form of that is this — **a screen, not a document
     * type**. Every allocation goes through `recordPayment()`, which is the settlement path that already
     * exists and is already tested: the same FX treatment, the same realised difference, the same status
     * transition, the same attribution to the invoice. Nothing new posts anything.
     *
     * **What this deliberately does not buy** is money on account with no invoice behind it. The plan says
     * to wait until somebody has that problem, and it is right to: an unallocated receipt needs somewhere
     * to sit, which is a table, which is the document type this avoids. So the allocations must add up to
     * the receipt, and a customer who overpays is refused with a sentence rather than silently left with a
     * credit nobody can see.
     *
     * **`$holdOnAccount` is what §2.2 deferred, and it changes the refusal rather than removing it.** With it
     * false — the default, and every existing caller — the allocations must still add up to the receipt, and
     * they still must for the part being allocated. What it adds is somewhere for the rest to go: a
     * `CustomerCredit` against 2600, which is a balance the schema can hold and a later invoice can draw on.
     *
     * @param  array<int|string, float>  $allocations  invoice id => amount to settle
     * @return array<int, Invoice> the invoices as they now stand
     */
    public function recordBatchReceipt(float $received, string $date, array $allocations, ?string $reference = null, bool $holdOnAccount = false): array
    {
        $allocations = array_filter(
            array_map(fn (mixed $amount): float => round((float) $amount, 2), $allocations),
            fn (float $amount): bool => $amount > 0,
        );

        if ($allocations === [] && ! $holdOnAccount) {
            throw new InvalidArgumentException('Allocate the receipt to at least one invoice.');
        }

        $allocated = round(array_sum($allocations), 2);
        $received = round($received, 2);
        $remainder = round($received - $allocated, 2);

        // Over-allocating still invents money, whatever the caller asked for: the allocations cannot come to
        // more than arrived.
        if ($remainder < -0.005) {
            throw new InvalidArgumentException(sprintf(
                'The allocations come to %s and only %s was received. A receipt cannot settle more than arrived.',
                number_format($allocated, 2),
                number_format($received, 2),
            ));
        }

        // Under-allocating is now a choice rather than an error — but only when it was actually chosen. The
        // message is the one §2.2 wrote, plus the way out that did not exist when it was written.
        if ($remainder >= 0.01 && ! $holdOnAccount) {
            throw new InvalidArgumentException(sprintf(
                'The allocations come to %s and the receipt is %s. Either allocate the remaining %s, or hold '
                .'it on account for this customer.',
                number_format($allocated, 2),
                number_format($received, 2),
                number_format($remainder, 2),
            ));
        }

        return TenantTransaction::run(function () use ($allocations, $date, $reference, $holdOnAccount, $remainder): array {
            $settled = [];
            $contactId = null;

            foreach ($allocations as $invoiceId => $amount) {
                $invoice = Invoice::query()->whereKey($invoiceId)->firstOrFail();
                $contactId = $invoice->contact_id;

                // One transaction around the lot, so a receipt that fails on its fourth invoice leaves the
                // first three unsettled too. Half a receipt recorded is worse than none: the customer's
                // balance is then wrong in a way that reconciles to nothing.
                $settled[] = $this->recordPayment($invoice, $amount, $date, null, null, $reference);
            }

            // Inside the same transaction as the settlements: a receipt that is part allocation and part
            // deposit is one event, and recording half of it is the failure the loop above avoids.
            if ($holdOnAccount && $remainder >= 0.01) {
                if ($contactId === null) {
                    throw new InvalidArgumentException('A receipt held on account has to say which customer it came from.');
                }

                $this->holdOnAccount(Contact::query()->findOrFail($contactId), $remainder, $date, $reference);
            }

            return $settled;
        });
    }

    /**
     * Money received from a customer against no invoice — a deposit, a retainer, an over-payment.
     *
     * `docs/erpnext-gap-plan.md` §2.2's deferral, built when somebody had the problem: fixed-price work
     * billed 40% up front has nowhere to sit otherwise, and the alternatives are a journal entry typed by
     * hand or an invoice raised for money rather than for work.
     *
     * Debit the bank, credit 2600 Customer Advances. It is a liability, not revenue: the company owes the
     * customer either the work or the money back, and it becomes revenue when an invoice draws on it.
     */
    public function holdOnAccount(Contact $contact, float $amount, string $date, ?string $reference = null, ?int $cashAccountId = null): CustomerCredit
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException('An amount held on account has to be more than nothing.');
        }

        return TenantTransaction::run(function () use ($contact, $amount, $date, $reference, $cashAccountId): CustomerCredit {
            $memo = "On account from {$contact->name}".(filled($reference) ? " — {$reference}" : '');

            $entry = $this->postSystemEntry($date, $memo, [
                ['account_id' => $cashAccountId ?? $this->accountId('1100'), 'debit_amount' => $amount, 'description' => $memo],
                ['account_id' => $this->advancesAccountId(), 'credit_amount' => $amount, 'description' => $memo],
            ]);

            return CustomerCredit::create([
                'contact_id' => $contact->getKey(),
                'received_on' => $date,
                'amount' => $amount,
                'applied_amount' => 0,
                'reference' => $reference,
                'journal_entry_id' => $entry->getKey(),
            ]);
        });
    }

    /**
     * Put money already held against an invoice.
     *
     * **No new posting logic, and that is the whole design.** `recordPayment()` already takes the account the
     * money comes from, so applying a credit is an ordinary settlement whose "bank" is 2600: the receivable
     * is relieved, the liability is discharged, and no cash moves because none does. Every guard on that
     * method — the status check, the overpayment check, the note refusals — applies unchanged.
     */
    public function applyCredit(CustomerCredit $credit, Invoice $invoice, float $amount, string $date): Invoice
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Apply more than nothing, or apply nothing at all.');
        }

        if ($credit->contact_id !== $invoice->contact_id) {
            throw new InvalidArgumentException(
                "That credit belongs to a different customer. Money held for one customer cannot settle another's invoice."
            );
        }

        if ($amount > $credit->remaining() + 0.005) {
            throw new InvalidArgumentException(sprintf(
                'Only %s is left on that credit, and %s was asked for.',
                number_format($credit->remaining(), 2),
                number_format($amount, 2),
            ));
        }

        return TenantTransaction::run(function () use ($credit, $invoice, $amount, $date): Invoice {
            $settled = $this->recordPayment(
                $invoice,
                $amount,
                $date,
                null,
                $this->advancesAccountId(),
                'from credit held '.$credit->received_on?->format('j M Y'),
            );

            $credit->forceFill(['applied_amount' => round((float) $credit->applied_amount + $amount, 2)])->save();

            return $settled;
        });
    }

    /** Where money received against no invoice sits until one draws on it. */
    protected function advancesAccountId(): int
    {
        $id = Account::where('code', '2600')->value('id');

        if (! $id) {
            throw new InvalidArgumentException(
                'This company\'s chart of accounts has no Customer Advances account (2600), so there is nowhere '
                .'to hold money that is not against an invoice. Add it to the chart, or run php artisan '
                .'tenants:seed-baseline to take the shipped one.'
            );
        }

        return (int) $id;
    }

    /**
     * Settle `$amount` of the invoice, of which `$withheld` arrived as a tax deduction certificate.
     *
     * The customer's side of §153. A corporate customer paying a 100,000 invoice transfers 92,000 and
     * hands over a certificate for the 8,000 it deducted and paid to FBR on this company's behalf. The
     * invoice is settled in full — the customer owes nothing — but only 92,000 reached the bank, and the
     * 8,000 is an asset (1260 Advance Income Tax) the company's own return absorbs. Before this, that
     * receipt was either an invoice that never closed or a manual journal per certificate.
     *
     * `$amount` is still what the invoice is settled by, so every guard and status transition below is
     * untouched; `$withheld` only decides how the debit side of the same entry is split. The supplier's
     * side — what this company withholds when it pays — is `WithholdingService`, and deliberately not this.
     *
     * ponytail: base currency only. A certificate is a PKR document about a PKR liability; splitting a
     * foreign receipt would need a foreign amount on the tax line too, and nobody has asked.
     */
    public function recordPayment(Invoice $invoice, float $amount, string $date, ?float $rate = null, ?int $cashAccountId = null, ?string $reference = null, float $withheld = 0.0, ?string $certificate = null): Invoice
    {
        if (! $invoice->isOpen()) {
            throw new InvalidArgumentException("Only issued or partially paid invoices accept payments (invoice is {$invoice->status}).");
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        // A credit note is not paid, and refusing is deliberate rather than unfinished.
        //
        // Nobody pays a credit note: it reduces what the customer owes, and the balance it
        // leaves is a credit they hold. Left to fall through, the branch below would treat it
        // as a purchase and relieve accounts payable — crediting a supplier balance that has
        // nothing to do with this customer, in a way that reconciles to nothing.
        //
        // Handing the money back is a real thing, and it is a **refund**: a bank payment out,
        // with its own account and, on a foreign invoice, its own realised difference at the
        // rate the bank actually gave. That is a money path this method does not model for
        // outgoing customer payments, and guessing at its FX treatment would produce a number
        // that looks authoritative and is wrong. So it says so, and the credit stands against
        // the customer's balance until somebody records the refund as what it is.
        if ($invoice->isCreditNote()) {
            throw new InvalidArgumentException(
                "Credit note {$invoice->invoice_number} is not paid — it reduces what this customer owes, "
                .'and shows as a credit against their balance. If you are handing the money back, record '
                .'that as a payment from the bank account it left.'
            );
        }

        // The same refusal on the purchase side — Phase 5. A debit note reduces what this company owes and
        // is settled by paying the supplier less, not by a payment of its own; recording one against the
        // note would relieve A/P twice for the same claim.
        if ($invoice->isDebitNote()) {
            throw new InvalidArgumentException(
                "Debit note {$invoice->invoice_number} is not paid — it reduces what this supplier is owed, "
                .'and shows against their balance. Pay the bill less the note, or if they have refunded the '
                .'money, record that as a receipt into the bank account it arrived in.'
            );
        }

        if ($amount > $invoice->outstanding() + 0.001) {
            throw new InvalidArgumentException(
                "Payment {$amount} exceeds outstanding balance {$invoice->outstanding()} on {$invoice->invoice_number}."
            );
        }

        $withheld = round($withheld, 2);

        if ($withheld < 0) {
            throw new InvalidArgumentException('Tax withheld cannot be negative.');
        }

        if ($withheld > $amount + 0.001) {
            throw new InvalidArgumentException(sprintf(
                'The customer cannot have withheld %s from a settlement of %s. The amount is what the invoice '
                .'is settled by — money received plus tax withheld — so it has to be at least the tax.',
                number_format($withheld, 2),
                number_format($amount, 2),
            ));
        }

        if ($withheld > 0 && $invoice->kind !== Invoice::KIND_SALE) {
            throw new InvalidArgumentException(
                'Tax withheld by a customer is recorded on a sale. What this company withholds when it pays a '
                .'supplier is deducted on the payment itself, at approval.'
            );
        }

        if ($withheld > 0 && $invoice->isForeignCurrency()) {
            throw new InvalidArgumentException(
                "{$invoice->invoice_number} is billed in {$invoice->currencyCode()}. Tax withheld by a customer "
                .'can only be recorded on an invoice in the base currency.'
            );
        }

        $advanceTax = $withheld > 0 ? $this->advanceTaxAccountId() : null;

        return TenantTransaction::run(function () use ($invoice, $amount, $date, $rate, $cashAccountId, $reference, $withheld, $certificate, $advanceTax) {
            $cash = $cashAccountId ?? $this->accountId('1100');
            $paid = round((float) $invoice->amount_paid + $amount, 2);

            $settlement = $this->settlement($invoice, $amount, $paid, $date, $rate);

            // The receipt's debit side, split: what reached the bank, and what the customer paid to FBR
            // instead. Both relieve the receivable; only one of them is money. The tax line is filtered
            // out below when it is zero, so an ordinary receipt posts exactly what it always did.
            $taxDescription = 'Tax withheld by customer'.(filled($certificate) ? " — cert. {$certificate}" : '');

            $lines = $invoice->kind === Invoice::KIND_SALE
                ? [
                    ['account_id' => $cash, 'debit_amount' => round($settlement['received'] - $withheld, 2)] + $this->cashInCurrency($invoice, $cash, $amount, $settlement, 'debit') + ['description' => "Payment {$invoice->invoice_number}"],
                    ['account_id' => $advanceTax ?? $cash, 'debit_amount' => $withheld, 'description' => "{$taxDescription} — {$invoice->invoice_number}"],
                    ['account_id' => $this->accountId('1250'), 'credit_amount' => $settlement['relieved'], 'description' => "Payment {$invoice->invoice_number}"]
                    + $this->clearedInCurrency($invoice, $amount, 'credit'),
                ]
                : [
                    ['account_id' => $this->accountId('2400'), 'debit_amount' => $settlement['relieved'], 'description' => "Payment {$invoice->invoice_number}"]
                    + $this->clearedInCurrency($invoice, $amount, 'debit'),
                    ['account_id' => $cash, 'credit_amount' => $settlement['received']] + $this->cashInCurrency($invoice, $cash, $amount, $settlement, 'credit') + ['description' => "Payment {$invoice->invoice_number}"],
                ];

            if (abs($settlement['difference']) >= 0.005) {
                $lines[] = $this->realisedLine($invoice, $settlement['difference']);
            }

            // The settlement belongs to the invoice it settles: same project, same customer, and the
            // receipt is the second half of that invoice's story rather than an event of its own.
            //
            // The reference, where one is given, is what ties five settlements back to the one transfer
            // that paid them — `docs/erpnext-gap-plan.md` Phase 2. It is in the memo rather than in a
            // column because there is no receipt row to put it on: a customer receipt writes a journal
            // entry and updates the invoice, and nothing else. That ceiling is named in the plan.
            $this->postSystemEntry(
                $date,
                "Payment against {$invoice->invoice_number}".(filled($reference) ? " — {$reference}" : ''),
                $lines,
                $invoice,
            );

            $invoice->update([
                'amount_paid' => $paid,
                'status' => $paid + 0.001 >= (float) $invoice->total
                    ? Invoice::STATUS_PAID
                    : Invoice::STATUS_PARTIALLY_PAID,
            ]);

            InvoiceEvent::record(
                $invoice,
                InvoiceEvent::PAYMENT,
                ($invoice->outstanding() > 0
                    ? 'Part payment received on '.$date.', '.number_format($invoice->outstanding(), 2).' still outstanding'
                    : 'Paid in full on '.$date)
                    .(abs($settlement['difference']) >= 0.005
                        ? ' — '.($settlement['difference'] > 0 ? 'gain' : 'loss').' of '
                            .number_format(abs($settlement['difference']), 2).' on the exchange rate'
                        : ''),
                $amount,
            );

            // Its own event, so the invoice's history says why the bank shows less than the settlement, and
            // carries the certificate reference — the thing the tax return is checked against.
            if ($withheld > 0) {
                InvoiceEvent::record(
                    $invoice,
                    InvoiceEvent::TAX_WITHHELD,
                    number_format($withheld, 2).' withheld by the customer on '.$date
                        .(filled($certificate) ? " — certificate {$certificate}" : ' — no certificate reference given'),
                    $withheld,
                );
            }

            return $invoice;
        });
    }

    /**
     * Spread this invoice's revenue — or a bill's cost — over the months its lines say the service covers.
     *
     * `docs/erpnext-gap-plan.md` §4 item 3, the half Phase 5 left: "the generator from an invoice line".
     * Phase 5's reasoning stands — deferring is an act somebody performs, not something a row does by
     * itself — so this is an action on an issued invoice rather than a side effect of issuing one. What
     * changed is that `invoice_lines.service_from/to` now exist, so the act needs no figures typed: each
     * dated line becomes one `DeferralService` schedule for its net amount, over its own months, out of
     * the account the line actually posted to.
     *
     * Once per invoice. The `DEFERRED` event is the guard, and it is the invoice's own history rather than a
     * column because the thing somebody arguing about a month's revenue will open is that history.
     *
     * @return array{deferred: float, schedules: int}
     */
    public function deferOverServicePeriod(Invoice $invoice): array
    {
        if ($invoice->isDraft() || $invoice->status === Invoice::STATUS_VOID) {
            throw new InvalidArgumentException('Only an issued invoice can be deferred: nothing has been posted to spread yet.');
        }

        if ($invoice->isAdjustment()) {
            throw new InvalidArgumentException(
                'A credit or debit note is not deferred. It corrects a document that was; correct the schedule that '
                .'document created instead.'
            );
        }

        if ($invoice->events()->where('event', InvoiceEvent::DEFERRED)->exists()) {
            throw new InvalidArgumentException("{$invoice->invoice_number} has already been deferred — see its history.");
        }

        $lines = $invoice->lines()->with('product')->get()
            ->filter(fn (InvoiceLine $line): bool => $line->serviceMonths() !== null && $line->netAmount() > 0)
            // A bill's product lines went to inventory, not to an expense, and stock is not deferred.
            ->reject(fn (InvoiceLine $line): bool => $invoice->kind === Invoice::KIND_PURCHASE && $line->product_id !== null);

        if ($lines->isEmpty()) {
            throw new InvalidArgumentException(
                'No line on this invoice carries a service period. Put "Service from" and "Service to" on the lines '
                .'that are for a period rather than a delivery, and try again.'
            );
        }

        $deferrals = app(DeferralService::class);

        return TenantTransaction::run(function () use ($invoice, $lines, $deferrals): array {
            $deferred = 0.0;

            foreach ($lines as $line) {
                $description = "{$invoice->invoice_number} — {$line->description}";

                $result = $invoice->kind === Invoice::KIND_SALE
                    ? $deferrals->deferRevenue($line->netAmount(), $line->serviceMonths(), $line->service_from->toDateString(), $description, $this->revenueAccountId($line))
                    : $deferrals->deferExpense($line->netAmount(), $line->serviceMonths(), $line->service_from->toDateString(), $description, $this->expenseAccountId($line));

                $deferred += $result['deferred'] ?? $line->netAmount();
            }

            InvoiceEvent::record(
                $invoice,
                InvoiceEvent::DEFERRED,
                number_format($deferred, 2).' spread over the service period on '.$lines->count()
                    .' line'.($lines->count() === 1 ? '' : 's').' — recognised a month at a time by scheduled entries',
                round($deferred, 2),
            );

            return ['deferred' => round($deferred, 2), 'schedules' => $lines->count()];
        });
    }

    /**
     * Refuse a sale that would take the customer past what the company is prepared to be owed.
     *
     * `docs/erpnext-gap-plan.md` §4 item 5, and "the one control on this list that prevents a loss rather
     * than reporting one". Two rules, both off until somebody sets them: a limit on the contact, and a
     * company-wide number of days an invoice may be overdue before new billing to that customer stops.
     *
     * Exposure is what ageing reads — every open sale and credit note, signed, in base currency — plus this
     * invoice. Sales only: a credit note *reduces* exposure and a purchase is somebody else's credit decision.
     *
     * The escape hatch is a permission and not a parameter, for the reason Phase 3's ledger freeze gives:
     * without one the first genuine exception forces somebody to raise the limit, issue, and remember to
     * put it back, and nothing records that the window was open. `InvoiceOverrideCreditLimit` is granted to
     * Manager and above, and the refusal names it.
     */
    protected function assertWithinCredit(Invoice $invoice): void
    {
        if ($invoice->kind !== Invoice::KIND_SALE || auth()->user()?->can('InvoiceOverrideCreditLimit')) {
            return;
        }

        $contact = $invoice->contact;
        $limit = $contact?->credit_limit;
        $overdueDays = (int) setting('invoicing.credit_block_overdue_days', 0);

        if ($limit === null && $overdueDays <= 0) {
            return;
        }

        $open = Invoice::query()
            ->where('contact_id', $invoice->contact_id)
            ->whereIn('kind', [Invoice::KIND_SALE, Invoice::KIND_CREDIT_NOTE])
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
            ->whereKeyNot($invoice->getKey())
            ->get();

        if ($limit !== null) {
            $owed = round((float) $open->sum(fn (Invoice $other): float => $other->signedBaseOutstanding()), 2);
            $exposure = round($owed + $invoice->baseTotal(), 2);

            if ($exposure > (float) $limit + 0.005) {
                throw new InvalidArgumentException(sprintf(
                    '%s already owes %s and this invoice would take that to %s, past their credit limit of %s. '
                    .'Collect first, raise the limit on the contact, or have somebody who may override credit '
                    .'limits issue it.',
                    $contact->name,
                    number_format($owed, 2),
                    number_format($exposure, 2),
                    number_format((float) $limit, 2),
                ));
            }
        }

        if ($overdueDays > 0) {
            $cutoff = Carbon::parse($invoice->invoice_date)->subDays($overdueDays)->toDateString();

            $stale = $open->first(fn (Invoice $other): bool => $other->kind === Invoice::KIND_SALE
                && $other->outstanding() > 0.004
                && $other->due_date !== null
                && $other->due_date->toDateString() < $cutoff);

            if ($stale) {
                throw new InvalidArgumentException(sprintf(
                    '%s is more than %d days overdue on %s (due %s, %s outstanding), so new invoices to %s are '
                    .'held. Collect it, or have somebody who may override credit limits issue this one.',
                    $contact->name,
                    $overdueDays,
                    $stale->invoice_number,
                    $stale->due_date->format('d M Y'),
                    number_format($stale->outstanding(), 2),
                    $contact->name,
                ));
            }
        }
    }

    /**
     * Where tax a customer withheld is held until the company's own return absorbs it.
     *
     * Named the way `DeferralService::accountFor()` names its accounts: a company whose chart predates
     * 1260 gets told what to run, rather than a ModelNotFoundException from three frames down.
     */
    protected function advanceTaxAccountId(): int
    {
        $id = Account::where('code', '1260')->value('id');

        if (! $id) {
            throw new InvalidArgumentException(
                'This company\'s chart of accounts has no Advance Income Tax account (1260), so there is nowhere '
                .'to put the tax the customer withheld. Add it to the chart, or run php artisan '
                .'tenants:seed-baseline to take the shipped one.'
            );
        }

        return (int) $id;
    }

    /**
     * What a payment is worth, what it clears, and the difference between them.
     *
     * `relieved` is computed as the *difference of two cumulative amounts* rather than
     * as this payment translated on its own. Rounding each part separately can leave a
     * cent in the receivable after the last payment, and an invoice marked paid in full
     * with something still sitting against it is the kind of residue nobody finds. Taking
     * the whole minus what was cleared before makes the reliefs telescope to exactly the
     * invoice's base total, whatever the instalments were.
     *
     * @return array{received: float, relieved: float, rate: float, difference: float}
     */
    protected function settlement(Invoice $invoice, float $amount, float $paidToDate, string $date, ?float $rate): array
    {
        if (! $invoice->isForeignCurrency()) {
            return ['received' => $amount, 'relieved' => $amount, 'rate' => 1.0, 'difference' => 0.0];
        }

        $converted = Money::toBase($amount, $invoice->currencyCode(), $date, $rate);

        $invoiceRate = $invoice->rate();
        $relieved = round(
            round($paidToDate * $invoiceRate, 2) - round((float) $invoice->amount_paid * $invoiceRate, 2),
            2,
        );

        return [
            'received' => $converted['base'],
            'relieved' => $relieved,
            'rate' => $converted['rate'],
            // Positive is a gain for a sale: more base currency arrived than the
            // receivable was carried at. For a purchase the sign works out the same way,
            // because less base currency left than the payable was carried at.
            'difference' => round($converted['base'] - $relieved, 2)
                * ($invoice->kind === Invoice::KIND_SALE ? 1 : -1),
        ];
    }

    /**
     * The foreign amount on the cash line, but only if the cash account holds that
     * currency.
     *
     * Euros paid into a euro account are euros in that account, and its own balance
     * should say so. Euros paid into a rupee account arrived as rupees — the bank
     * converted them — and writing euros into it would claim it holds a currency it does
     * not.
     *
     * @param  array{received: float, relieved: float, rate: float, difference: float}  $settlement
     * @return array<string, mixed>
     */
    protected function cashInCurrency(Invoice $invoice, int $cashAccountId, float $amount, array $settlement, string $side): array
    {
        if (! $invoice->isForeignCurrency()) {
            return [];
        }

        $holds = Account::whereKey($cashAccountId)->value('currency_code') === $invoice->currencyCode();

        return $holds
            ? [
                'currency_code' => $invoice->currencyCode(),
                'rate' => $settlement['rate'],
                "foreign_{$side}_amount" => $amount,
            ]
            : [];
    }

    /**
     * The foreign amount coming off the receivable or the payable.
     *
     * Always recorded, whichever account the money landed in: it is what makes the
     * control account's foreign balance fall to zero when the invoice is settled, so that
     * "what is still owed in euros" is answerable from the ledger and not only from the
     * documents.
     *
     * @return array<string, mixed>
     */
    protected function clearedInCurrency(Invoice $invoice, float $amount, string $side): array
    {
        return $invoice->isForeignCurrency()
            ? [
                'currency_code' => $invoice->currencyCode(),
                'rate' => $invoice->rate(),
                "foreign_{$side}_amount" => $amount,
            ]
            : [];
    }

    /**
     * The realised gain or loss, on its own account.
     *
     * Apart from the unrealised kind on purpose: this one is money the company has, and
     * a reader who cannot tell the two apart cannot tell how much of a good year was
     * banked and how much was a rate on a reporting date.
     *
     * @return array<string, mixed>
     */
    protected function realisedLine(Invoice $invoice, float $difference): array
    {
        $account = app(CurrencyRevaluationService::class)->realisedAccount();

        return [
            'account_id' => $account->id,
            'debit_amount' => $difference < 0 ? -$difference : 0,
            'credit_amount' => $difference > 0 ? $difference : 0,
            'description' => ($difference > 0 ? 'Exchange gain on ' : 'Exchange loss on ')
                .$invoice->invoice_number,
        ];
    }

    /**
     * Void an unpaid invoice: reverse its posting entry and undo the
     * inventory movements (sales restore a lot, purchase lots must be
     * unconsumed).
     */
    public function void(Invoice $invoice, ?User $user = null): Invoice
    {
        if (! $invoice->isOpen()) {
            throw new InvalidArgumentException("Only issued or partially paid invoices can be voided (invoice is {$invoice->status}).");
        }

        if ((float) $invoice->amount_paid > 0) {
            throw new InvalidArgumentException('Invoices with recorded payments cannot be voided.');
        }

        // An invoice that has already been credited must not also be voided: both reverse
        // the same posting, so doing both takes the revenue out twice and leaves the
        // receivable negative by the invoice total. The order is the fix — void the credit
        // note, then void the invoice — and it has to be explicit, because from here there
        // is no way to tell which of the two the person actually meant to undo.
        if ($invoice->creditedTotal() > 0) {
            throw new InvalidArgumentException(
                "Invoice {$invoice->invoice_number} has been credited, and voiding it as well would "
                .'reverse the same posting twice. Void the credit note first if the credit was raised '
                .'in error.'
            );
        }

        $this->assertFbrAllowsVoid($invoice);

        return TenantTransaction::run(function () use ($invoice, $user) {
            foreach ($invoice->stockMovements()->get() as $movement) {
                $this->undoMovement($invoice, $movement);
            }

            if ($invoice->journalEntry) {
                $this->journalEntryService->reverse($invoice->journalEntry, $user);
            }

            $invoice->update(['status' => Invoice::STATUS_VOID]);

            InvoiceEvent::record($invoice, InvoiceEvent::VOIDED, 'Voided and its posting reversed');

            return $invoice;
        });
    }

    /**
     * Raise a credit note against a sale invoice.
     *
     * This is the answer to the third row of the correction table in
     * `docs/fbr-digital-invoicing-plan.md` §2 — the reported invoice, past 72 hours, that
     * `void()` refuses to touch. It is also the ordinary answer to an overcharge, a duplicate
     * bill, or a consignment that arrived damaged, none of which need FBR to be involved at
     * all.
     *
     * **It creates a draft and stops.** `issue()` is what posts, exactly as for an invoice,
     * and for the same reason `QuotationService::convertToInvoice()` stops at draft: the thing
     * that transmits is the thing that cannot be taken back, so it stays a deliberate second
     * act. A credit note nobody has issued has changed no balance and can simply be deleted.
     *
     * **The rate is inherited, never re-fetched.** A foreign-currency invoice booked its
     * receivable at the rate fixed on the day it was issued. Crediting it at today's rate
     * would clear a different amount than was booked and leave a permanent stub in A/R that
     * looks like an unpaid balance and can never be collected — while quietly recognising an
     * exchange gain nobody earned. `fixRate()` leaves a rate that is already set alone, so
     * copying it here is sufficient and the two documents translate identically.
     *
     * @param  string  $reason  Why. Required — see the column comment.
     * @param  array<int, array<string, mixed>>|null  $lines  Explicit lines for a partial
     *                                                        credit. Null credits the whole
     *                                                        invoice by copying its lines.
     * @param  array{ref?: string, granted_on?: string}|null  $approval  A rule 22 extension
     *                                                                   from the Commissioner,
     *                                                                   needed only past 180
     *                                                                   days. See
     *                                                                   `assertAdjustmentWindowAllows()`.
     */
    public function creditNote(Invoice $invoice, string $reason, ?array $lines = null, ?array $approval = null): Invoice
    {
        if (! $invoice->isSale()) {
            throw new InvalidArgumentException(
                $invoice->isCreditNote()
                    ? 'A credit note cannot be credited. Void it if it was raised in error.'
                    // Until Phase 5 this said a bill "is corrected by the supplier, who issues the credit
                    // note to you", which was true of the *tax* and not of the books: what the company owes
                    // still had to come down. The Debit action is that, and this sentence now points at it.
                    : 'Only a customer invoice can be credited — a supplier bill is corrected with a debit '
                        .'note, which is the Debit action on the bill itself.'
            );
        }

        if ($invoice->isDraft()) {
            throw new InvalidArgumentException(
                "Invoice {$invoice->invoice_number} is still a draft, so it has posted nothing and there "
                .'is nothing to credit. Edit or delete the draft instead.'
            );
        }

        if ($invoice->status === Invoice::STATUS_VOID) {
            throw new InvalidArgumentException(
                "Invoice {$invoice->invoice_number} has been voided, so its posting is already reversed. "
                .'A credit note on top of it would reverse the same money twice.'
            );
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A credit note needs a reason. It is the first thing asked about a reversed sale, and '
                .'the only part of it that cannot be reconstructed from the figures.'
            );
        }

        $available = $invoice->creditableAmount();

        if ($available <= 0.004) {
            throw new InvalidArgumentException(
                "Invoice {$invoice->invoice_number} has already been credited in full."
            );
        }

        $this->assertAdjustmentWindowAllows($invoice, $approval);

        $rows = $this->creditLines($invoice, $lines);

        if ($rows === []) {
            throw new InvalidArgumentException('A credit note with no lines credits nothing.');
        }

        ['subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $total]
            = $this->totalsFromRows((bool) $invoice->tax_inclusive, $rows);

        // Before anything is written, and against what is *left* rather than the invoice
        // total, so two partial credits cannot together exceed it. Checking after creation
        // would work — the transaction would roll back — but the figure in the message is
        // clearer when nothing has been built from it yet.
        if ($total > round($available, 2) + 0.004) {
            throw new InvalidArgumentException(
                "This credit note comes to {$total}, but only {$available} of invoice "
                ."{$invoice->invoice_number} is left to credit."
            );
        }

        if ($total <= 0) {
            throw new InvalidArgumentException('A credit note must credit something. This one comes to nothing.');
        }

        return TenantTransaction::run(function () use ($invoice, $reason, $rows, $subtotal, $tax, $total, $approval) {
            $note = Invoice::create([
                'kind' => Invoice::KIND_CREDIT_NOTE,
                'credits_invoice_id' => $invoice->getKey(),
                'credit_reason' => trim($reason),
                // Stamped on the document, not looked up later. The extension was granted for
                // this correction, and a settings flag saying "we have approvals" would not say
                // which — the same rule as the leave-year window and the proration divisor.
                'commissioner_approval_ref' => $approval['ref'] ?? null,
                'commissioner_approved_on' => $approval['granted_on'] ?? null,
                'status' => Invoice::STATUS_DRAFT,
                'contact_id' => $invoice->contact_id,
                'project_id' => $invoice->project_id,
                'currency_code' => $invoice->currency_code,
                // Inherited, not re-fetched. See the docblock.
                'exchange_rate' => $invoice->exchange_rate,
                // The tax treatment has to match the invoice's, or the reversal of the tax
                // will not equal what was charged.
                'tax_inclusive' => $invoice->tax_inclusive,
                'invoice_date' => now()->toDateString(),
                'fiscal_year_id' => FiscalYear::where('is_active', true)->value('id'),
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'memo' => "Credit note against {$invoice->invoice_number}",
            ]);

            foreach ($rows as $row) {
                $note->lines()->create($row);
            }

            InvoiceEvent::record(
                $invoice,
                InvoiceEvent::CREDITED,
                "Credit note {$note->invoice_number} raised: {$note->credit_reason}",
                $total,
            );

            return $note->refresh();
        });
    }

    /**
     * Raise a debit note against a purchase bill — `docs/erpnext-gap-plan.md` Phase 5.
     *
     * The purchase mirror of `creditNote()`, and the answer to a claim against a supplier: goods returned, a
     * bill that overstated the quantity, a back-charge for work redone. It debits Accounts Payable and
     * credits back whatever the bill charged, so what the company owes falls by exactly what the bill raised.
     *
     * **It creates a draft and stops**, exactly as a credit note does, and `issue()` is what posts. A debit
     * note nobody has issued has changed no balance and can be deleted.
     *
     * **The rate is inherited, never re-fetched**, for the reason the credit note's docblock gives at
     * length: crediting a foreign bill at today's rate clears a different amount than was booked and leaves
     * a permanent stub in A/P that looks like an unpaid balance.
     *
     * **No FBR window and no Commissioner extension**, which is the one place this is not the mirror. Rule
     * 22 bounds the adjustment of tax on a supply *this company made and reported*; a supplier's bill is a
     * document received, and the input-tax adjustment rides on the credit note the supplier issues. Applying
     * `assertAdjustmentWindowAllows()` here would refuse a bookkeeping correction by citing a rule about
     * somebody else's document — and would say "output tax" while doing it.
     *
     * **Written out rather than shared with `creditNote()`, deliberately.** The two differ in four guards
     * and every message, and a shared method taking a noun would produce sentences assembled out of
     * fragments — "there is nothing to {$verb}" — for the one part of this feature a person actually reads.
     * The arithmetic *is* shared: `creditLines()`, `totalsFromRows()` and the `creditableAmount()` ceiling
     * are the same in both directions, and those are the parts that could silently disagree.
     *
     * @param  string  $reason  Why. Required, and the place to put the supplier's own credit note reference.
     * @param  array<int, array<string, mixed>>|null  $lines  Explicit lines for a partial debit. Null
     *                                                        reverses the whole bill.
     */
    public function debitNote(Invoice $bill, string $reason, ?array $lines = null): Invoice
    {
        if ($bill->kind !== Invoice::KIND_PURCHASE) {
            throw new InvalidArgumentException(
                $bill->isDebitNote()
                    ? 'A debit note cannot be debited. Void it if it was raised in error.'
                    : 'Only a supplier bill can be debited — a customer invoice is corrected with a credit '
                        .'note, which is the Credit action on the invoice itself.'
            );
        }

        if ($bill->isDraft()) {
            throw new InvalidArgumentException(
                "Bill {$bill->invoice_number} is still a draft, so it has posted nothing and there is "
                .'nothing to debit. Edit or delete the draft instead.'
            );
        }

        if ($bill->status === Invoice::STATUS_VOID) {
            throw new InvalidArgumentException(
                "Bill {$bill->invoice_number} has been voided, so its posting is already reversed. A debit "
                .'note on top of it would reverse the same money twice.'
            );
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A debit note needs a reason. It is what the supplier will ask about, and the only part of '
                .'the claim the figures cannot show — put their own credit note reference here too.'
            );
        }

        // The same ceiling as a credit note's, through the same relation: `credits_invoice_id` means "the
        // document this one adjusts", so two partial debit notes cannot together exceed the bill.
        $available = $bill->creditableAmount();

        if ($available <= 0.004) {
            throw new InvalidArgumentException("Bill {$bill->invoice_number} has already been debited in full.");
        }

        $rows = $this->creditLines($bill, $lines);

        if ($rows === []) {
            throw new InvalidArgumentException('A debit note with no lines debits nothing.');
        }

        ['subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $total]
            = $this->totalsFromRows((bool) $bill->tax_inclusive, $rows);

        if ($total > round($available, 2) + 0.004) {
            throw new InvalidArgumentException(
                "This debit note comes to {$total}, but only {$available} of bill "
                ."{$bill->invoice_number} is left to debit."
            );
        }

        if ($total <= 0) {
            throw new InvalidArgumentException('A debit note must debit something. This one comes to nothing.');
        }

        return TenantTransaction::run(function () use ($bill, $reason, $rows, $subtotal, $tax, $total) {
            $note = Invoice::create([
                'kind' => Invoice::KIND_DEBIT_NOTE,
                'credits_invoice_id' => $bill->getKey(),
                'credit_reason' => trim($reason),
                'status' => Invoice::STATUS_DRAFT,
                'contact_id' => $bill->contact_id,
                'project_id' => $bill->project_id,
                'currency_code' => $bill->currency_code,
                // Inherited, not re-fetched. See the docblock.
                'exchange_rate' => $bill->exchange_rate,
                // The tax treatment has to match the bill's, or the reversal of the input tax will not equal
                // what was charged.
                'tax_inclusive' => $bill->tax_inclusive,
                'invoice_date' => now()->toDateString(),
                'fiscal_year_id' => FiscalYear::where('is_active', true)->value('id'),
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'memo' => "Debit note against {$bill->invoice_number}",
            ]);

            foreach ($rows as $row) {
                $note->lines()->create($row);
            }

            InvoiceEvent::record(
                $bill,
                InvoiceEvent::DEBITED,
                "Debit note {$note->invoice_number} raised: {$note->credit_reason}",
                $total,
            );

            return $note->refresh();
        });
    }

    /**
     * The rule 22 adjustment window, and the one thing the Commissioner can be recorded as
     * having permitted.
     *
     * This answers §9.7 of the plan, which asked whether a credit note is sufficient after 72
     * hours or whether Commissioner approval is needed even for that. The question contained a
     * conflation, and separating it is the whole of this method:
     *
     *  - **72 hours** (STGO 01 of 2026) governs amending or cancelling the *e-invoice*. Past
     *    it, changing the invoice needs the Commissioner's prior approval, and
     *    `assertFbrAllowsVoid()` refuses — this application cannot obtain that approval and
     *    must not act as though the invoice changed.
     *  - **180 days** (section 9 of the Sales Tax Act 1990, rules 20–22 of the Sales Tax Rules
     *    2006) governs the *credit note*, which is not an amendment at all but a second
     *    document adjusting the tax. It needs no approval to issue. What it needs is to be
     *    within 180 days of the supply — and the proviso to rule 22 lets the Commissioner
     *    extend that once, by a further 180 days, on written request with reasons recorded.
     *
     * So the answer is: **sufficient, and unapproved, until day 180.** After that the company
     * still does not need permission to correct its books — it needs permission to be *late*,
     * and that is a different thing, obtained outside this application and recorded here.
     *
     * **Only enforced where reporting is on.** The same reasoning as
     * `FbrReconciliation::unreported()`: rule 22 binds a sales-tax-registered person making
     * taxable supplies, so applying it to a company below the threshold would refuse a
     * perfectly ordinary correction by citing a rule that does not reach them. Off by default,
     * so a company that touches nothing behaves exactly as before.
     *
     * @param  array{ref?: string, granted_on?: string}|null  $approval
     */
    protected function assertAdjustmentWindowAllows(Invoice $invoice, ?array $approval): void
    {
        if (! (bool) setting('fbr.enabled', false)) {
            return;
        }

        if ($invoice->creditNoteWindowOpen()) {
            return;
        }

        $deadline = $invoice->creditNoteDeadline();
        $days = (int) setting('fbr.credit_note_days', 180);

        if (! filled($approval['ref'] ?? null)) {
            throw new InvalidArgumentException(
                "Invoice {$invoice->invoice_number} was supplied on {$invoice->invoice_date->format('d M Y')}, "
                ."more than {$days} days ago, so a credit note against it no longer adjusts output tax on its "
                .'own (rule 22 of the Sales Tax Rules 2006). The Commissioner may extend that period by a '
                .'further '.(int) setting('fbr.credit_note_extension_days', 180)
                .' days on written request — record the reference of that extension here and the credit note '
                .'can be raised.'
            );
        }

        // Beyond the extension there is nothing left to record. Rule 22's proviso allows one
        // further period, not a renewable one, so accepting a reference here would be storing
        // evidence for an adjustment that is inadmissible anyway — which is worse than
        // refusing, because it looks like it was checked.
        if (now()->greaterThan($invoice->creditNoteExtendedDeadline())) {
            throw new InvalidArgumentException(
                "Invoice {$invoice->invoice_number} is past even the extended adjustment period, which ended on "
                .$invoice->creditNoteExtendedDeadline()->format('d M Y')
                .'. Rule 22 allows one further period of '
                .(int) setting('fbr.credit_note_extension_days', 180)
                .' days, not a renewable one, so a credit note raised now would not adjust output tax. This '
                .'needs your tax advisor rather than this screen.'
            );
        }

        // Recorded rather than merely permitted: an unexplained credit note raised on day 300
        // is indistinguishable from an authorised one, and exactly one of them is admissible.
        activity('Invoice')
            ->performedOn($invoice)
            ->causedBy(auth()->user())
            ->event('commissioner-extension-relied-on')
            ->withProperties([
                'deadline' => $deadline->toDateString(),
                'approval_ref' => $approval['ref'],
                'approved_on' => $approval['granted_on'] ?? null,
            ])
            ->log("Credit note against {$invoice->invoice_number} raised outside the {$days}-day adjustment "
                ."period under Commissioner extension {$approval['ref']}");
    }

    /**
     * Document totals from line figures, the way `validateTotals()` defines them.
     *
     * Extracted because two callers must agree exactly: `creditNote()` totals the rows it is
     * about to write, and `issue()` re-totals whatever the draft ended up containing. If those
     * two disagreed by a rounding step, a credit note would be created happily and then refuse
     * to issue, complaining about arithmetic nobody performed.
     *
     * Subtotal is net of tax on an inclusive document and gross on an exclusive one — the same
     * asymmetry as `validateTotals()`, in the same direction, because that is the check this
     * has to survive.
     *
     * @param  array<int, array<string, mixed>>  $rows  Each with `line_total` and `tax_amount`.
     * @return array{subtotal: float, tax_amount: float, total: float}
     */
    protected function totalsFromRows(bool $taxInclusive, array $rows): array
    {
        $subtotal = round(array_sum(array_map(
            fn (array $row): float => $taxInclusive
                ? round((float) $row['line_total'] - (float) $row['tax_amount'], 2)
                : (float) $row['line_total'],
            $rows
        )), 2);

        $tax = round(array_sum(array_map(fn (array $row): float => (float) $row['tax_amount'], $rows)), 2);

        return ['subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => round($subtotal + $tax, 2)];
    }

    /**
     * Re-total a document from its lines, trusting the tax each line already carries.
     *
     * The credit-note counterpart to `applyTaxes()`. Same job, one deliberate difference: it
     * never consults a tax rate. See `issue()`.
     */
    protected function totalFromLines(Invoice $invoice): Invoice
    {
        $rows = $invoice->lines()->get()->map(fn (InvoiceLine $line): array => [
            'line_total' => (float) $line->line_total,
            'tax_amount' => (float) $line->tax_amount,
        ])->all();

        $invoice->forceFill($this->totalsFromRows((bool) $invoice->tax_inclusive, $rows))->save();

        return $invoice->refresh();
    }

    /**
     * The lines a credit note is made of, normalised.
     *
     * With no lines given this is a **full credit: the invoice's own lines, copied**. Copied
     * rather than recomputed, and that is the important half. `account_id` and `tax_rate_id`
     * come across so the credit debits the same revenue account the sale credited, and
     * `tax_amount` comes across as the figure actually charged rather than what the rate says
     * today — if a rate has been edited since, or a product repointed at a different revenue
     * account, recomputing would net the invoice against something else and leave both
     * accounts permanently wrong by the difference. A correction that does not reverse what
     * was posted is not a correction.
     *
     * With lines given this is a partial credit, and they are normalised rather than trusted:
     * `line_total` is always derived from quantity × unit price, because `validateTotals()`
     * checks exactly that at issue and a caller-supplied total that disagrees would fail there
     * instead of here. Tax is computed from the line's rate only when the caller did not say —
     * an explicit zero is honoured, since crediting an exempt portion of a taxed invoice is a
     * real thing.
     *
     * @param  array<int, array<string, mixed>>|null  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function creditLines(Invoice $invoice, ?array $lines): array
    {
        if ($lines === null) {
            return $invoice->lines()->get()->map(fn (InvoiceLine $line): array => [
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
                'account_id' => $line->account_id,
                'tax_rate_id' => $line->tax_rate_id,
                'tax_amount' => (float) $line->tax_amount,
            ])->all();
        }

        return array_values(array_map(function (array $row) use ($invoice): array {
            $quantity = (float) ($row['quantity'] ?? 1);
            $unitPrice = (float) ($row['unit_price'] ?? 0);
            $lineTotal = round($quantity * $unitPrice, 2);
            $rateId = $row['tax_rate_id'] ?? null;

            if (array_key_exists('tax_amount', $row) && $row['tax_amount'] !== null) {
                $taxAmount = round((float) $row['tax_amount'], 2);
            } elseif ($rateId && $rate = TaxRate::find($rateId)) {
                $taxAmount = $invoice->tax_inclusive
                    ? $rate->taxWithin($lineTotal)
                    : $rate->taxOn($lineTotal);
            } else {
                $taxAmount = 0.0;
            }

            return [
                'product_id' => $row['product_id'] ?? null,
                'description' => $row['description'] ?? 'Credit',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'account_id' => $row['account_id'] ?? null,
                'tax_rate_id' => $rateId,
                'tax_amount' => $taxAmount,
            ];
        }, $lines));
    }

    /**
     * Refuse a void that FBR would not accept.
     *
     * Voiding used to be purely local, and for an invoice nobody reported it
     * still is. Once an invoice has been accepted by FBR it may only be
     * cancelled inside FBR's own system, and only within the correction window;
     * after that the correction needs the prior approval of the Commissioner
     * Inland Revenue. Neither of those is something this application can do.
     *
     * So the refusal is the feature. A void button that quietly produces a
     * locally-voided invoice which FBR still considers live leaves the books and
     * the tax authority disagreeing permanently, with nothing reporting the
     * divergence — strictly worse than an error message telling somebody what
     * they actually have to do.
     *
     * See docs/fbr-digital-invoicing-plan.md §2.
     */
    private function assertFbrAllowsVoid(Invoice $invoice): void
    {
        $status = $invoice->fbr_status;

        // Never live at FBR. `rejected` belongs here: FBR refused it, so there
        // is nothing on their side to withdraw and the local record is the only
        // record. Null covers a row written before the columns existed.
        if (in_array($status, [null, Invoice::FBR_NOT_REQUIRED, Invoice::FBR_REJECTED], true)) {
            return;
        }

        // Already withdrawn at FBR, so the books may now follow. This is the
        // second half of a within-window cancellation: cancel there, then void
        // here.
        if ($status === Invoice::FBR_CANCELLED) {
            return;
        }

        if (in_array($status, [Invoice::FBR_PENDING, Invoice::FBR_SUBMITTED], true)) {
            throw new InvalidArgumentException(
                "Invoice {$invoice->invoice_number} is being reported to FBR and cannot be voided until "
                .'that finishes. Wait for it to be accepted or rejected, then try again.'
            );
        }

        // Accepted, and still correctable — but only at FBR, and not from here.
        if ($invoice->fbrCorrectionWindowOpen()) {
            $closesAt = $invoice->fbrCorrectionWindowClosesAt();

            throw new InvalidArgumentException(
                "Invoice {$invoice->invoice_number} has been reported to FBR and must be cancelled in the "
                ."FBR system first — the window closes at {$closesAt->toDayDateTimeString()}. "
                .'Once FBR shows it cancelled, void it here to reverse the posting.'
            );
        }

        // The third row of the correction table, and the one that now has an answer. Until
        // credit notes existed this message named a document nobody could raise; "Credit" on
        // the invoice does exactly what it says.
        throw new InvalidArgumentException(
            "Invoice {$invoice->invoice_number} was reported to FBR and the correction window has closed, "
            .'so it can no longer be cancelled — a correction now needs the prior approval of the '
            .'Commissioner Inland Revenue. Use Credit instead: a credit note reverses the sale in your '
            .'books and is itself reported, leaving both records agreeing.'
        );
    }

    /**
     * Open sale invoices bucketed by days overdue: current / 31-60 / 61-90 / 90+.
     */
    public function outstandingReceivables(?string $asOf = null): array
    {
        return $this->aging(Invoice::KIND_SALE, $asOf);
    }

    public function outstandingPayables(?string $asOf = null): array
    {
        return $this->aging(Invoice::KIND_PURCHASE, $asOf);
    }

    protected function aging(string $kind, ?string $asOf): array
    {
        $asOf = Carbon::parse($asOf ?? now()->toDateString());

        $buckets = ['current' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0];
        $invoices = [];

        // Each side includes its own adjustment note, bucketed by its own date and subtracted rather than
        // added — see `signedOutstanding()`. Leaving them out would overstate every receivable figure in the
        // application by exactly the credits outstanding, which is the reading a company chases a customer
        // for money over; and on the payables side, the reading it pays a supplier over.
        //
        // The debit note is Phase 5 of `docs/erpnext-gap-plan.md`, and this line is why it needed no new
        // report: payables ageing, `outstandingPayables()` and therefore Phase 2's control-account check all
        // read this one query.
        $kinds = $kind === Invoice::KIND_SALE
            ? [Invoice::KIND_SALE, Invoice::KIND_CREDIT_NOTE]
            : [$kind, Invoice::KIND_DEBIT_NOTE];

        foreach (Invoice::whereIn('kind', $kinds)->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])->with('contact')->get() as $invoice) {
            $days = (int) Carbon::parse($invoice->due_date ?? $invoice->invoice_date)->diffInDays($asOf, false);
            $bucket = match (true) {
                $days <= 30 => 'current',
                $days <= 60 => '31-60',
                $days <= 90 => '61-90',
                default => '90+',
            };

            // The buckets are in base currency. Adding up invoices in a mixture of
            // currencies also produces a number, which is exactly how that goes wrong
            // without anybody noticing.
            $buckets[$bucket] = round($buckets[$bucket] + $invoice->signedBaseOutstanding(), 2);
            $invoices[] = [
                'invoice_number' => $invoice->invoice_number,
                'contact' => $invoice->contact->name,
                'outstanding' => $invoice->signedOutstanding(),
                'currency_code' => $invoice->currencyCode(),
                'outstanding_base' => $invoice->signedBaseOutstanding(),
                'days_overdue' => max(0, $days),
                'bucket' => $bucket,
            ];
        }

        return ['as_of' => $asOf->toDateString(), 'buckets' => $buckets, 'total' => round(array_sum($buckets), 2), 'invoices' => $invoices];
    }

    /**
     * Sale: debit A/R for the total; credit revenue per line; credit
     * sales tax; product lines add a COGS leg (debit COGS, credit
     * inventory) at the valuation engine's cost, consuming lots.
     */
    protected function saleEntryLines(Invoice $invoice, $lines): array
    {
        $entryLines = [[
            'account_id' => $this->accountId('1250'),
            'debit_amount' => (float) $invoice->total,
            'description' => $invoice->invoice_number,
            '_fx' => self::FX_CONTROL,
        ]];

        foreach ($lines as $line) {
            // A negative line is a credit to the customer — a rebate, or money
            // being handed back — and reduces revenue, so it is a debit. Booking it
            // as a negative credit would be dropped by postSystemEntry's filter and
            // leave the entry short by that amount, failing on the balance check
            // with nothing to point at.
            //
            // Net of the line's own tax: on an inclusive invoice the line amount is
            // gross, and crediting all of it to revenue would book the tax as
            // income and leave the entry unbalanced by exactly the tax.
            $amount = $invoice->tax_inclusive ? $line->netAmount() : (float) $line->line_total;

            $entryLines[] = [
                'account_id' => $this->revenueAccountId($line),
                $amount < 0 ? 'debit_amount' : 'credit_amount' => abs($amount),
                'description' => $line->description,
                '_fx' => self::FX_LINE,
            ];

            if ($line->product_id) {
                $cogs = $this->valuation->costOfSale($line->product, (float) $line->quantity);
                $line->cogs = $cogs; // stashed for recordMovement below

                $entryLines[] = [
                    'account_id' => $line->product->cogs_account_id ?? $this->accountId('5050'),
                    'debit_amount' => $cogs,
                    'description' => "COGS {$line->product->sku}",
                ];
                $entryLines[] = [
                    'account_id' => $line->product->inventory_account_id ?? $this->accountId('1300'),
                    'credit_amount' => $cogs,
                    'description' => "COGS {$line->product->sku}",
                ];
            }
        }

        foreach ($this->taxByAccount($invoice, $lines) as $accountId => $amount) {
            $entryLines[] = [
                'account_id' => $accountId,
                'credit_amount' => $amount,
                'description' => "Sales tax {$invoice->invoice_number}",
                '_fx' => self::FX_LINE,
            ];
        }

        return $entryLines;
    }

    /**
     * Credit note: the sale, mirrored. Credit A/R for the total; debit revenue per line;
     * debit the sales tax accounts.
     *
     * Every leg is the opposite of `saleEntryLines()` and nothing else differs, which is the
     * property that matters: a full credit note against an invoice nets the receivable, the
     * revenue and the output tax to exactly zero. That is what makes it a correction rather
     * than a second, differently-shaped transaction that happens to be about the same money.
     *
     * **No COGS leg**, matching the absence of a stock movement in `issue()`. Reversing COGS
     * would say the goods are back and worth what they cost; nothing here knows that. So the
     * cost of the original sale stays charged, which is the right answer whenever the credit
     * is for an overcharge, a duplicate, or goods that were never coming back — and the
     * conservative one otherwise.
     */
    protected function creditNoteEntryLines(Invoice $invoice, $lines): array
    {
        $entryLines = [[
            'account_id' => $this->accountId('1250'),
            'credit_amount' => (float) $invoice->total,
            'description' => $invoice->invoice_number,
            '_fx' => self::FX_CONTROL,
        ]];

        foreach ($lines as $line) {
            // Net of the line's own tax on an inclusive document, for the same reason as the
            // sale: debiting the gross to revenue would take the tax out of income instead of
            // out of the tax account, and leave the entry short by exactly the tax.
            $amount = $invoice->tax_inclusive ? $line->netAmount() : (float) $line->line_total;

            // Mirrored, including the negative case. A negative line on a credit note is a
            // credit against a credit — an item excluded from the refund — so it goes back on
            // the credit side rather than being posted as a negative debit and dropped.
            $entryLines[] = [
                'account_id' => $this->revenueAccountId($line),
                $amount < 0 ? 'credit_amount' : 'debit_amount' => abs($amount),
                'description' => $line->description,
                '_fx' => self::FX_LINE,
            ];
        }

        foreach ($this->taxByAccount($invoice, $lines) as $accountId => $amount) {
            $entryLines[] = [
                'account_id' => $accountId,
                'debit_amount' => $amount,
                'description' => "Sales tax reversed {$invoice->invoice_number}",
                '_fx' => self::FX_LINE,
            ];
        }

        return $entryLines;
    }

    /**
     * Purchase: debit inventory per product line (creating lots) or the
     * line's expense account; debit input tax; credit A/P for the total.
     */
    protected function purchaseEntryLines(Invoice $invoice, $lines): array
    {
        $entryLines = [];

        foreach ($lines as $line) {
            // Net, for the same reason as a sale: the tax is recoverable and
            // belongs on the tax account, not in the cost of the thing bought.
            $amount = $invoice->tax_inclusive ? $line->netAmount() : (float) $line->line_total;

            $entryLines[] = [
                'account_id' => $line->product_id
                    ? ($line->product->inventory_account_id ?? $this->accountId('1300'))
                    : $this->expenseAccountId($line),
                // A negative line is money the supplier is not being paid — retention
                // held back, or a back-charge — and reduces what is owed, so it is a
                // credit. The mirror of the same rule in saleEntryLines(), and for the
                // same reason: booking it as a negative debit would be dropped by
                // postSystemEntry's filter, leaving the entry short by that amount and
                // failing the balance check with nothing to point at.
                //
                // Nothing wrote one of these until subcontract retention did; see
                // docs/construction-management-plan.md §10.4 and
                // PurchaseInvoiceNegativeLineTest.
                $amount < 0 ? 'credit_amount' : 'debit_amount' => abs($amount),
                'description' => $line->description,
                '_fx' => self::FX_LINE,
            ];
        }

        foreach ($this->taxByAccount($invoice, $lines) as $accountId => $amount) {
            $entryLines[] = [
                'account_id' => $accountId,
                'debit_amount' => $amount,
                'description' => "Input tax {$invoice->invoice_number}",
                '_fx' => self::FX_LINE,
            ];
        }

        $entryLines[] = [
            'account_id' => $this->accountId('2400'),
            'credit_amount' => (float) $invoice->total,
            'description' => $invoice->invoice_number,
            '_fx' => self::FX_CONTROL,
        ];

        return $entryLines;
    }

    /**
     * Debit note: the exact reverse of `purchaseEntryLines()` — `docs/erpnext-gap-plan.md` Phase 5.
     *
     * Debit A/P for the total, credit back each line's expense or inventory account, credit back the input
     * tax. Every leg is the opposite of the bill's and nothing else differs, which is the property that
     * matters: a full debit note against a bill nets the payable, the cost and the input tax to exactly
     * zero. That is what makes it a correction rather than a second, differently-shaped transaction about
     * the same money.
     *
     * **It credits the inventory account without moving stock**, and that pairing is deliberate — see the
     * comment in `issue()`. The bill's own posting debited inventory *and* created lots at a known cost;
     * reversing the value here without reversing the quantity leaves the two disagreeing until somebody
     * records the movement. Reversing the quantity automatically would instead consume lots at whatever the
     * valuation engine's current cost is, which after any other receipt is not the cost these goods came in
     * at — a made-up cost, silently, which is worse than a gap a stock count finds.
     *
     * Worth knowing: nothing currently *notices* that gap. `LedgerControls` holds Receivables and Payables
     * and no inventory control, so 1300 against the valuation is a third pair somebody could register — and
     * a returned-goods debit note is the case that would make it worth having.
     */
    protected function debitNoteEntryLines(Invoice $invoice, $lines): array
    {
        $entryLines = [[
            'account_id' => $this->accountId('2400'),
            'debit_amount' => (float) $invoice->total,
            'description' => $invoice->invoice_number,
            '_fx' => self::FX_CONTROL,
        ]];

        foreach ($lines as $line) {
            // Net of the line's own tax on an inclusive document, for the same reason as the bill: crediting
            // the gross back to the expense would take the tax out of the cost instead of out of the tax
            // account, and leave the entry short by exactly the tax.
            $amount = $invoice->tax_inclusive ? $line->netAmount() : (float) $line->line_total;

            // Mirrored, including the negative case. A negative line on a debit note is a debit against a
            // debit — retention *not* being reclaimed — so it goes back on the debit side rather than being
            // posted as a negative credit and dropped by postSystemEntry's filter.
            $entryLines[] = [
                'account_id' => $line->product_id
                    ? ($line->product->inventory_account_id ?? $this->accountId('1300'))
                    : $this->expenseAccountId($line),
                $amount < 0 ? 'debit_amount' : 'credit_amount' => abs($amount),
                'description' => $line->description,
                '_fx' => self::FX_LINE,
            ];
        }

        foreach ($this->taxByAccount($invoice, $lines) as $accountId => $amount) {
            $entryLines[] = [
                'account_id' => $accountId,
                'credit_amount' => $amount,
                'description' => "Input tax reversed {$invoice->invoice_number}",
                '_fx' => self::FX_LINE,
            ];
        }

        return $entryLines;
    }

    /**
     * The invoice's tax, split by the account each rate posts to.
     *
     * Grouped rather than lumped onto one account, because two taxes on one
     * invoice — a sales tax and a provincial levy — are two liabilities to two
     * authorities, and a single 2150 balance cannot be filed against either.
     *
     * An invoice with no rates on its lines falls back to its own tax_amount on the
     * shipped account: that is every invoice raised before rates existed, and they
     * must keep posting exactly as they did.
     *
     * @return array<int, float> account id => tax
     */
    protected function taxByAccount(Invoice $invoice, $lines): array
    {
        $byAccount = [];

        foreach ($lines as $line) {
            $tax = round((float) $line->tax_amount, 2);

            if ($tax === 0.0 || ! $line->taxRate) {
                continue;
            }

            $accountId = $line->taxRate->accountId() ?? $this->accountId(TaxRate::DEFAULT_ACCOUNT_CODE);
            $byAccount[$accountId] = round(($byAccount[$accountId] ?? 0) + $tax, 2);
        }

        if ($byAccount === [] && (float) $invoice->tax_amount > 0) {
            $byAccount[$this->accountId(TaxRate::DEFAULT_ACCOUNT_CODE)] = round((float) $invoice->tax_amount, 2);
        }

        return $byAccount;
    }

    protected function recordMovement(Invoice $invoice, InvoiceLine $line, JournalEntry $entry): StockMovement
    {
        if ($invoice->kind === Invoice::KIND_SALE) {
            return $line->product->movements()->create([
                'type' => 'sale',
                'quantity' => -(float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'total_cost' => $line->cogs,
                'movement_date' => $invoice->invoice_date->toDateString(),
                'reference' => $invoice->invoice_number,
                'journal_entry_id' => $entry->id,
                'source_type' => ModuleMap::alias(InvoiceLine::class),
                'source_id' => $line->id,
            ]);
        }

        return $line->product->movements()->create([
            'type' => 'purchase',
            'quantity' => (float) $line->quantity,
            'unit_cost' => (float) $line->unit_price,
            'remaining_quantity' => (float) $line->quantity,
            'movement_date' => $invoice->invoice_date->toDateString(),
            'reference' => $invoice->invoice_number,
            'journal_entry_id' => $entry->id,
            'source_type' => ModuleMap::alias(InvoiceLine::class),
            'source_id' => $line->id,
        ]);
    }

    /**
     * Undo one invoice movement. Sales restore the consumed cost as a
     * fresh lot; purchase lots must be fully unconsumed to void.
     */
    protected function undoMovement(Invoice $invoice, StockMovement $movement): void
    {
        if ($movement->type === 'sale') {
            $quantity = abs((float) $movement->quantity);

            $movement->product->movements()->create([
                'type' => 'adjustment',
                'quantity' => $quantity,
                'unit_cost' => round((float) $movement->total_cost / $quantity, 4),
                'remaining_quantity' => $quantity,
                'movement_date' => now()->toDateString(),
                'reference' => "VOID {$invoice->invoice_number}",
            ]);

            return;
        }

        if ((float) $movement->remaining_quantity < (float) $movement->quantity) {
            throw new InvalidArgumentException(
                "Cannot void {$invoice->invoice_number}: purchase lot for {$movement->product->sku} is partially consumed."
            );
        }

        $movement->product->movements()->create([
            'type' => 'adjustment',
            'quantity' => -(float) $movement->quantity,
            'total_cost' => round((float) $movement->quantity * (float) $movement->unit_cost, 2),
            'movement_date' => now()->toDateString(),
            'reference' => "VOID {$invoice->invoice_number}",
        ]);

        $movement->update(['remaining_quantity' => 0]);
    }

    /**
     * Work out each line's tax from its rate, and the invoice's from its lines.
     *
     * Only for invoices that use rates. A line with no rate contributes no tax and,
     * when *no* line has one, the invoice's own tax_amount is left exactly as
     * entered — that is the legacy case, and rewriting it would silently restate
     * every invoice raised before rates existed.
     *
     * Subtotal is always net of tax. On an inclusive invoice the line amount is
     * gross, so the revenue is what is left once the tax comes out; on an exclusive
     * one the line amount is already net and the tax is added on top. Either way
     * total = subtotal + tax, so the ledger sees one shape.
     */
    public function applyTaxes(Invoice $invoice): Invoice
    {
        $lines = $invoice->lines()->with('taxRate')->get();

        if ($lines->every(fn (InvoiceLine $line): bool => $line->tax_rate_id === null)) {
            return $invoice;
        }

        $tax = 0.0;
        $net = 0.0;

        foreach ($lines as $line) {
            $gross = round((float) $line->line_total, 2);
            $rate = $line->taxRate;

            $lineTax = match (true) {
                $rate === null => 0.0,
                (bool) $invoice->tax_inclusive => $rate->taxWithin($gross),
                default => $rate->taxOn($gross),
            };

            if (round((float) $line->tax_amount, 2) !== $lineTax) {
                $line->forceFill(['tax_amount' => $lineTax])->save();
            }

            $tax = round($tax + $lineTax, 2);
            $net = round($net + ($invoice->tax_inclusive ? $gross - $lineTax : $gross), 2);
        }

        $invoice->forceFill([
            'subtotal' => $net,
            'tax_amount' => $tax,
            'total' => round($net + $tax, 2),
        ])->save();

        return $invoice->refresh();
    }

    protected function validateTotals(Invoice $invoice, $lines): void
    {
        foreach ($lines as $line) {
            if (round((float) $line->quantity * (float) $line->unit_price, 2) !== round((float) $line->line_total, 2)) {
                throw new InvalidArgumentException("Line '{$line->description}' total does not match quantity × unit price.");
            }
        }

        // Net of tax, because that is what subtotal means. On an exclusive invoice
        // that is the line sum; on an inclusive one the tax has to come out of it
        // first, or every inclusive invoice would look mis-added.
        $subtotal = round(
            $lines->sum(fn (InvoiceLine $line): float => $invoice->tax_inclusive
                ? $line->netAmount()
                : (float) $line->line_total),
            2
        );

        if ($subtotal !== round((float) $invoice->subtotal, 2)) {
            throw new InvalidArgumentException("Invoice subtotal {$invoice->subtotal} does not match line sum {$subtotal}.");
        }

        if (round($subtotal + (float) $invoice->tax_amount, 2) !== round((float) $invoice->total, 2)) {
            throw new InvalidArgumentException('Invoice total must equal subtotal plus tax.');
        }
    }

    /**
     * @param  Invoice|null  $source  what produced this posting — `docs/erpnext-gap-plan.md` Phase 1
     */
    protected function postSystemEntry(string $date, string $memo, array $lines, ?Invoice $source = null): JournalEntry
    {
        $entry = $this->journalEntryService->create([
            'entry_date' => $date,
            'entry_type' => 'general',
            'memo' => $memo,
            /*
             * Two keys in a header this method already built, and they are the whole of Phase 1's first
             * item here. An invoice knows its project and its customer, so an entry that records the
             * invoice knows them too — which is what lets a profit and loss be read by project without a
             * dimension column on the line. Nullable, so a caller that has no document passes nothing and
             * nothing changes.
             */
            'source_type' => $source === null ? null : $source::class,
            'source_id' => $source?->getKey(),
        ], array_values(array_filter($lines, fn ($l) => ($l['debit_amount'] ?? 0) > 0 || ($l['credit_amount'] ?? 0) > 0)));

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        $this->journalEntryService->post($entry);

        return $entry;
    }

    protected function revenueAccountId(InvoiceLine $line): int
    {
        if ($line->account_id) {
            return $line->account_id;
        }

        if ($line->product_id) {
            return $line->product->revenue_account_id ?? $this->accountId('4200');
        }

        return $this->accountId('4300');
    }

    protected function expenseAccountId(InvoiceLine $line): int
    {
        if (! $line->account_id) {
            throw new InvalidArgumentException("Non-product purchase line '{$line->description}' needs an account.");
        }

        return $line->account_id;
    }

    protected function accountId(string $code): int
    {
        return Account::where('code', $code)->firstOrFail()->id;
    }
}
