<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\CurrencyRevaluationService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Support\Money;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryValuationService;
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
        $this->applyTaxes($invoice);

        $lines = $invoice->lines()->with(['product', 'taxRate'])->get();

        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Invoice has no lines.');
        }

        $this->validateTotals($invoice, $lines);

        // Before anything is posted: an invoice in another currency is issued at one
        // rate, and that rate is what ties the document to the ledger from here on.
        $this->fixRate($invoice);

        return TenantTransaction::run(function () use ($invoice, $lines) {
            $entryLines = $this->translateDocument($invoice, $invoice->kind === Invoice::KIND_SALE
                ? $this->saleEntryLines($invoice, $lines)
                : $this->purchaseEntryLines($invoice, $lines));

            $entry = $this->postSystemEntry(
                $invoice->invoice_date->toDateString(),
                "{$invoice->invoice_number} — {$invoice->contact->name}",
                $entryLines
            );

            foreach ($lines as $line) {
                if ($line->product_id) {
                    $this->recordMovement($invoice, $line, $entry);
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
    public function recordPayment(Invoice $invoice, float $amount, string $date, ?float $rate = null, ?int $cashAccountId = null): Invoice
    {
        if (! $invoice->isOpen()) {
            throw new InvalidArgumentException("Only issued or partially paid invoices accept payments (invoice is {$invoice->status}).");
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        if ($amount > $invoice->outstanding() + 0.001) {
            throw new InvalidArgumentException(
                "Payment {$amount} exceeds outstanding balance {$invoice->outstanding()} on {$invoice->invoice_number}."
            );
        }

        return TenantTransaction::run(function () use ($invoice, $amount, $date, $rate, $cashAccountId) {
            $cash = $cashAccountId ?? $this->accountId('1100');
            $paid = round((float) $invoice->amount_paid + $amount, 2);

            $settlement = $this->settlement($invoice, $amount, $paid, $date, $rate);

            $lines = $invoice->kind === Invoice::KIND_SALE
                ? [
                    ['account_id' => $cash, 'debit_amount' => $settlement['received']] + $this->cashInCurrency($invoice, $cash, $amount, $settlement, 'debit') + ['description' => "Payment {$invoice->invoice_number}"],
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

            $this->postSystemEntry($date, "Payment against {$invoice->invoice_number}", $lines);

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

            return $invoice;
        });
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

        foreach (Invoice::where('kind', $kind)->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])->with('contact')->get() as $invoice) {
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
            $buckets[$bucket] = round($buckets[$bucket] + $invoice->baseOutstanding(), 2);
            $invoices[] = [
                'invoice_number' => $invoice->invoice_number,
                'contact' => $invoice->contact->name,
                'outstanding' => $invoice->outstanding(),
                'currency_code' => $invoice->currencyCode(),
                'outstanding_base' => $invoice->baseOutstanding(),
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
     * Purchase: debit inventory per product line (creating lots) or the
     * line's expense account; debit input tax; credit A/P for the total.
     */
    protected function purchaseEntryLines(Invoice $invoice, $lines): array
    {
        $entryLines = [];

        foreach ($lines as $line) {
            $entryLines[] = [
                'account_id' => $line->product_id
                    ? ($line->product->inventory_account_id ?? $this->accountId('1300'))
                    : $this->expenseAccountId($line),
                // Net, for the same reason as a sale: the tax is recoverable and
                // belongs on the tax account, not in the cost of the thing bought.
                'debit_amount' => $invoice->tax_inclusive ? $line->netAmount() : (float) $line->line_total,
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

    protected function postSystemEntry(string $date, string $memo, array $lines): JournalEntry
    {
        $entry = $this->journalEntryService->create([
            'entry_date' => $date,
            'entry_type' => 'general',
            'memo' => $memo,
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
