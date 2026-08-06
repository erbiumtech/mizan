<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Payroll\Models\Payslip;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Posts the payroll journal entries that are sitting unposted.
 *
 * With `accounting.auto_post_payroll` off, a payslip's entry is created and submitted for
 * approval only — status `pending_approval`, `is_posted` 0 — and **account balances do not
 * move**. Nothing is visibly wrong until money is paid: the payment debits 2300 Salaries
 * Payable, which was never credited, so the liability goes negative and the books say the
 * company paid out salary it never owed. That is how it was found.
 *
 * Turning the setting on fixes payslips saved from then on and does nothing for the backlog,
 * which is what this is for.
 *
 * Payroll-sourced entries only, deliberately. A manual entry awaiting approval is awaiting a
 * person; posting it from a command would be that approval, from nobody. Payroll is the one
 * case with a policy behind it — the same policy the auto-post setting expresses.
 *
 * Kept out of the console command so it can be tested without Spatie's TenantAware wrapper,
 * which needs a real per-tenant database connection (see PayrollAccountAudit for the same
 * split and the same reason).
 */
class PendingPayrollPoster
{
    public function __construct(private JournalEntryService $journalEntryService) {}

    /**
     * Payroll entries that have not reached the ledger, oldest first.
     *
     * Reversing entries are excluded: `reverse()` posts them as it makes them, so an unposted
     * one would be a corrupt row rather than a backlog item, and posting it would double the
     * reversal.
     *
     * @return Collection<int, JournalEntry>
     */
    public function pending(): Collection
    {
        return JournalEntry::forSource(Payslip::class)
            ->where('entry_type', '!=', 'reversing')
            ->where(fn ($query) => $query->whereNull('is_posted')->orWhere('is_posted', false))
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Post everything pending() finds, reporting per entry.
     *
     * One entry's failure does not stop the rest: a single payslip dated in a closed fiscal
     * year would otherwise hold back every other month's payroll, and each entry is posted in
     * its own transaction anyway (JournalEntryService::post).
     *
     * @return array{posted: array<int, string>, failed: array<string, string>}
     */
    public function post(bool $dryRun = false): array
    {
        $posted = [];
        $failed = [];

        foreach ($this->pending() as $entry) {
            if ($dryRun) {
                $posted[] = $entry->entry_number;

                continue;
            }

            try {
                $this->approveAsSystem($entry);
                $this->journalEntryService->post($entry->refresh());

                $posted[] = $entry->entry_number;
            } catch (Throwable $e) {
                $failed[$entry->entry_number] = $e->getMessage();
            }
        }

        return ['posted' => $posted, 'failed' => $failed];
    }

    /**
     * Approve without naming an approver, exactly as the auto-post branch of
     * PayrollPostingService does.
     *
     * Not through JournalEntryService::approve(), for two reasons that point the same way:
     * it refuses an approval by the entry's own creator (segregation of duties), which is
     * most of a backlog created by whoever ran payroll; and stamping some other manager as
     * `approved_by` would record an approval that person never gave. A null approver is the
     * honest entry — this was posted by the system, under the auto-post policy.
     */
    protected function approveAsSystem(JournalEntry $entry): void
    {
        if ($entry->isApproved()) {
            return;
        }

        $entry->update([
            'status' => JournalEntry::STATUS_APPROVED,
            'approved_at' => now(),
        ]);
    }
}
