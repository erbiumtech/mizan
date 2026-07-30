<?php

namespace App\Services;

use App\Support\ModuleMap;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\JournalEntry;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Issue, pay, and void invoices. Issuing posts one balanced journal
 * entry per invoice; product lines drive stock movements (sales consume
 * lots and book COGS in the same entry, purchases create lots).
 */
class InvoiceService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private InventoryValuationService $valuation,
    ) {
    }

    public function issue(Invoice $invoice): Invoice
    {
        if (! $invoice->isDraft()) {
            throw new InvalidArgumentException("Only draft invoices can be issued (invoice is {$invoice->status}).");
        }

        $lines = $invoice->lines()->with('product')->get();

        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Invoice has no lines.');
        }

        $this->validateTotals($invoice, $lines);

        return DB::transaction(function () use ($invoice, $lines) {
            $entryLines = $invoice->kind === Invoice::KIND_SALE
                ? $this->saleEntryLines($invoice, $lines)
                : $this->purchaseEntryLines($invoice, $lines);

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

            return $invoice;
        });
    }

    public function recordPayment(Invoice $invoice, float $amount, string $date): Invoice
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

        return DB::transaction(function () use ($invoice, $amount, $date) {
            $cash = $this->accountId('1100');

            $lines = $invoice->kind === Invoice::KIND_SALE
                ? [
                    ['account_id' => $cash, 'debit_amount' => $amount, 'description' => "Payment {$invoice->invoice_number}"],
                    ['account_id' => $this->accountId('1250'), 'credit_amount' => $amount, 'description' => "Payment {$invoice->invoice_number}"],
                ]
                : [
                    ['account_id' => $this->accountId('2400'), 'debit_amount' => $amount, 'description' => "Payment {$invoice->invoice_number}"],
                    ['account_id' => $cash, 'credit_amount' => $amount, 'description' => "Payment {$invoice->invoice_number}"],
                ];

            $this->postSystemEntry($date, "Payment against {$invoice->invoice_number}", $lines);

            $paid = round((float) $invoice->amount_paid + $amount, 2);

            $invoice->update([
                'amount_paid' => $paid,
                'status' => $paid + 0.001 >= (float) $invoice->total
                    ? Invoice::STATUS_PAID
                    : Invoice::STATUS_PARTIALLY_PAID,
            ]);

            return $invoice;
        });
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

        return DB::transaction(function () use ($invoice, $user) {
            foreach ($invoice->stockMovements()->get() as $movement) {
                $this->undoMovement($invoice, $movement);
            }

            if ($invoice->journalEntry) {
                $this->journalEntryService->reverse($invoice->journalEntry, $user);
            }

            $invoice->update(['status' => Invoice::STATUS_VOID]);

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

            $buckets[$bucket] = round($buckets[$bucket] + $invoice->outstanding(), 2);
            $invoices[] = [
                'invoice_number' => $invoice->invoice_number,
                'contact' => $invoice->contact->name,
                'outstanding' => $invoice->outstanding(),
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
        ]];

        foreach ($lines as $line) {
            $entryLines[] = [
                'account_id' => $this->revenueAccountId($line),
                'credit_amount' => (float) $line->line_total,
                'description' => $line->description,
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

        if ((float) $invoice->tax_amount > 0) {
            $entryLines[] = [
                'account_id' => $this->accountId('2150'),
                'credit_amount' => (float) $invoice->tax_amount,
                'description' => "Sales tax {$invoice->invoice_number}",
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
                'debit_amount' => (float) $line->line_total,
                'description' => $line->description,
            ];
        }

        if ((float) $invoice->tax_amount > 0) {
            $entryLines[] = [
                'account_id' => $this->accountId('2150'),
                'debit_amount' => (float) $invoice->tax_amount,
                'description' => "Input tax {$invoice->invoice_number}",
            ];
        }

        $entryLines[] = [
            'account_id' => $this->accountId('2400'),
            'credit_amount' => (float) $invoice->total,
            'description' => $invoice->invoice_number,
        ];

        return $entryLines;
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

    protected function validateTotals(Invoice $invoice, $lines): void
    {
        foreach ($lines as $line) {
            if (round((float) $line->quantity * (float) $line->unit_price, 2) !== round((float) $line->line_total, 2)) {
                throw new InvalidArgumentException("Line '{$line->description}' total does not match quantity × unit price.");
            }
        }

        $subtotal = round((float) $lines->sum('line_total'), 2);

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
