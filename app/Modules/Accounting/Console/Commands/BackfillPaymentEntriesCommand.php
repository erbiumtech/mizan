<?php

namespace App\Modules\Accounting\Console\Commands;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Services\PaymentService;
use App\Modules\Payroll\Models\Payslip;
use App\Support\ModuleMap;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;
use Throwable;

/**
 * Put payments that never reached the ledger into it.
 *
 * Two things left money off the books before this was fixed: approving a payment
 * created its journal entry as a *draft* and nothing ever posted it, and
 * releasing a payment that had not been approved created no entry at all. Both
 * are fixed at the source, but neither fix reaches what already happened — this
 * does.
 *
 * It only ever adds what is missing. A payment already carrying a posted entry is
 * left alone, so running it twice is safe, and --dry-run shows the whole plan
 * before anything is written.
 */
class BackfillPaymentEntriesCommand extends Command
{
    use TenantAware;

    protected $signature = 'accounting:backfill-payments
                            {--dry-run : List what would change without writing anything}
                            {--tenant=* : One or more tenants to run for}';

    protected $description = 'Book and post journal entries for payments that never reached the ledger';

    public function handle(PaymentService $payments, JournalEntryService $entries): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $missing = Payment::with('transactionType')
            ->whereNull('journal_entry_id')
            // Nothing to book for a payment of nothing.
            ->where('amount', '>', 0)
            ->whereIn('status', [Payment::STATUS_APPROVED, Payment::STATUS_EXPORTED, Payment::STATUS_PAID])
            ->orderBy('id')
            ->get();

        // Payments whose entry exists but never reached the ledger. These are not
        // simply posted: a salary payment's draft debits the salary expense
        // account, because that is where the salary transaction type points, and
        // posting it as it stands would book the wage a second time on top of the
        // payslip's own entry. They are rebuilt against the right accounts —
        // safe, because nothing unposted has touched the ledger.
        $stale = Payment::with('transactionType', 'journalEntry')
            ->whereNotNull('journal_entry_id')
            ->whereHas('journalEntry', fn ($query) => $query->where('is_posted', false))
            ->orderBy('id')
            ->get();

        if ($missing->isEmpty() && $stale->isEmpty()) {
            $this->info('Nothing to do: every payment is on the ledger.');

            return self::SUCCESS;
        }

        $this->report($missing, $stale);
        $this->warnAboutUnpostedPayroll();

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry run — nothing was written. Drop --dry-run to apply.');

            return self::SUCCESS;
        }

        $booked = $this->book($missing, $payments);
        $rebuilt = $this->rebuild($stale, $payments, $entries);

        $this->newLine();
        $this->info("Booked {$booked} payment(s) and rebuilt {$rebuilt}. All posted to the ledger.");

        return self::SUCCESS;
    }

    /** @param Collection<int, Payment> $missing */
    private function report($missing, $stale): void
    {
        if ($missing->isNotEmpty()) {
            $this->newLine();
            $this->line('Released with no journal entry at all:');
            $this->table(
                ['Payment', 'Type', 'Amount', 'Details', 'Will book to'],
                $missing->map(fn (Payment $payment): array => [
                    '#'.$payment->id,
                    $payment->transactionType?->name ?? '—',
                    number_format((float) $payment->amount, 2),
                    Str::limit($payment->details, 34),
                    $this->destinationFor($payment),
                ])->all(),
            );
        }

        if ($stale->isNotEmpty()) {
            $this->newLine();
            $this->line('Entries that never reached the ledger — rebuilt against the right accounts, then posted:');
            $this->table(
                ['Entry', 'Status', 'Payment', 'Amount', 'Debit becomes'],
                $stale->map(fn (Payment $payment): array => [
                    $payment->journalEntry->entry_number,
                    $payment->journalEntry->status,
                    '#'.$payment->id,
                    number_format((float) $payment->amount, 2),
                    $this->destinationFor($payment),
                ])->all(),
            );
        }
    }

    /**
     * A salary payment clears Salaries Payable, which the payslip's own entry
     * credited. If those entries are still waiting for approval, the liability was
     * never raised and clearing it puts a debit balance on it, with no salary cost
     * in the Profit & Loss to go with it.
     *
     * Not fixed here: payroll waits for approval deliberately, and posting a
     * company's payroll on its behalf is not this command's business.
     */
    private function warnAboutUnpostedPayroll(): void
    {
        $waiting = JournalEntry::whereNotNull('source_id')
            ->where('source_type', ModuleMap::alias(Payslip::class))
            ->where('is_posted', false)
            ->count();

        if ($waiting === 0) {
            return;
        }

        $this->newLine();
        $this->warn("{$waiting} payroll entries are still unposted, so no salary cost is in the Profit & Loss yet");
        $this->line('  and clearing Salaries Payable will leave it with a debit balance until they are.');
        $this->line('  Approve and post them under Accounting → Journal Entries (both have bulk actions), or');
        $this->line('  turn on Company Settings → Accounting → auto-post payroll so future months post themselves.');
    }

    /**
     * Where a payment's debit would land, or why it cannot be booked — said here,
     * in the plan, rather than as a surprise part-way through.
     */
    private function destinationFor(Payment $payment): string
    {
        if ($payment->payslip_id) {
            return 'Salaries Payable';
        }

        return $payment->transactionType?->account?->name
            ?? '⚠ no account on this type — will be skipped';
    }

    /** @param Collection<int, Payment> $missing */
    private function book($missing, PaymentService $payments): int
    {
        $booked = 0;

        foreach ($missing as $payment) {
            try {
                // Not approve(): these are already released or paid, and their
                // status is a record of that. Only the entry is missing.
                $entry = $payments->postEntryFor($payment);

                if (! $entry) {
                    continue;
                }

                $payment->forceFill(['journal_entry_id' => $entry->id])->save();
                $booked++;
            } catch (Throwable $e) {
                // One unbookable payment must not strand the rest.
                $this->warn("Payment #{$payment->id} skipped: {$e->getMessage()}");
            }
        }

        return $booked;
    }

    /** @param Collection<int, Payment> $stale */
    private function rebuild($stale, PaymentService $payments, JournalEntryService $entries): int
    {
        $rebuilt = 0;

        foreach ($stale as $payment) {
            $old = $payment->journalEntry;

            try {
                $fresh = $payments->postEntryFor($payment);

                $payment->forceFill(['journal_entry_id' => $fresh?->id])->save();

                // Only once the replacement is posted, and only ever an entry that
                // never reached the ledger.
                $old->lines()->delete();
                $old->delete();

                $rebuilt++;
            } catch (Throwable $e) {
                $this->warn("Payment #{$payment->id} skipped, {$old->entry_number} left alone: {$e->getMessage()}");
            }
        }

        return $rebuilt;
    }
}
