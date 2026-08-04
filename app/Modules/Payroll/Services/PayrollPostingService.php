<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Support\PayrollAccounts;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Support\ModuleMap;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use RuntimeException;

class PayrollPostingService
{
    public function __construct(private JournalEntryService $journalEntryService) {}

    /**
     * Create the journal entry for a payslip:
     *   Debit  5xxx expense accounts (gross earning components)
     *   Credit 2100 tax withheld, 2200 ESI, 1200 advances recovered,
     *          5600 meal recovery, 2300 net salary payable
     *
     * Any previous entry for the payslip is reversed/removed first, so this
     * is safe to call on both create and update.
     */
    public function postPayslip(Payslip $payslip): ?JournalEntry
    {
        // Payroll does not declare Accounting as a requirement — it works without
        // it, it just cannot post — so this is the one genuine cross-module write
        // that has to degrade rather than fail. Creating a payslip must succeed
        // for a company that runs payroll and keeps its books elsewhere, and must
        // keep succeeding if an Accounting licence is revoked underneath live
        // payroll data.
        //
        // Invoicing and Inventory need no equivalent guard: they *do* declare
        // Accounting as a requirement, so they are unavailable whenever it is
        // (see Modules::enabledFor) and cannot reach this situation.
        if (! modules()->enabled('accounting')) {
            return null;
        }

        // One transaction over the unwind and the replacement. Resolving the
        // accounts or validating the lines can still fail — a mapped account that
        // has been given a sub-account is enough — and until this was wrapped, the
        // reversal was already committed by then: the payslip's original entry had
        // been reversed and detached, its replacement never written, and every
        // retry failed the same way. Nothing to roll forward from, by hand.
        return TenantTransaction::run(function () use ($payslip) {
            $this->unwindForPayslip($payslip);

            return $this->writeEntry($payslip);
        });
    }

    /**
     * Build and post the payslip's entry. Assumes any earlier entry is unwound.
     */
    protected function writeEntry(Payslip $payslip): ?JournalEntry
    {
        $debits = [
            'basic_wage' => (float) $payslip->basic_wage,
            'medical_allowance' => (float) $payslip->medical_allowance,
            'petrol_allowance' => (float) $payslip->petrol_allowance,
            'device_allowance' => (float) $payslip->device_allowance,
            'bonus_overtime' => (float) $payslip->bonus + (float) $payslip->extra_work_hours,
            'expense_reimbursement' => (float) $payslip->expense_reimbursement,
        ];

        $credits = [
            'tax_payable' => (float) $payslip->withholding_tax,
            'esi_payable' => (float) $payslip->esi_health_insurance,
            'employee_advances' => (float) $payslip->advances,
            'meal_recovery' => (float) $payslip->meal_deduction,
            'salaries_payable' => (float) $payslip->net_salary,
        ];

        $lines = [];

        // Components added as data rather than columns, each to its own account. The
        // entry would not balance without them: net salary already includes the
        // allowance, so leaving the debit out would credit money nothing paid for and
        // the posting would be refused — correctly, but with nothing to point at.
        foreach ($this->dataDrivenLines($payslip) as [$accountId, $amount, $isEarning, $label]) {
            $lines[] = [
                'account_id' => $accountId,
                $isEarning ? 'debit_amount' : 'credit_amount' => $amount,
                'description' => "Payslip #{$payslip->id} {$label}",
            ];
        }

        foreach ($debits as $key => $amount) {
            if ($amount > 0) {
                $lines[] = [
                    'account_id' => $this->accountId($key),
                    'debit_amount' => round($amount, 2),
                    'description' => "Payslip #{$payslip->id} {$payslip->month}",
                ];
            }
        }

        foreach ($credits as $key => $amount) {
            if ($amount > 0) {
                $lines[] = [
                    'account_id' => $this->accountId($key),
                    'credit_amount' => round($amount, 2),
                    'description' => "Payslip #{$payslip->id} {$payslip->month}",
                ];
            }
        }

        if (count($lines) < 2) {
            return null; // empty payslip, nothing to book
        }

        $employeeName = $payslip->employee?->user?->name ?? "employee #{$payslip->employee_id}";

        $entry = $this->journalEntryService->create([
            'entry_date' => $this->entryDate($payslip),
            'memo' => "Payroll {$payslip->month} — {$employeeName}",
            'fiscal_year_id' => $payslip->fiscal_year_id,
            'source_type' => ModuleMap::alias(Payslip::class),
            'source_id' => $payslip->id,
        ], $lines);

        $this->journalEntryService->submitForApproval($entry);

        if (setting('accounting.auto_post_payroll')) {
            $entry->update([
                'status' => JournalEntry::STATUS_APPROVED,
                'approved_at' => now(),
            ]);
            $this->journalEntryService->post($entry);
        }

        return $entry;
    }

    /**
     * Reverse posted entries / delete unposted entries linked to a payslip.
     * Called before regenerating and on payslip deletion.
     */
    public function unwindForPayslip(Payslip $payslip): void
    {
        $entries = JournalEntry::forSource(Payslip::class, $payslip->id)
            ->where('entry_type', '!=', 'reversing')
            ->get();

        foreach ($entries as $entry) {
            if ($entry->is_posted) {
                $this->journalEntryService->reverse($entry);
                // Detach so a regenerated payslip's new entry is the only live link.
                $entry->update(['source_type' => null, 'source_id' => null]);
            } else {
                $entry->lines()->delete();
                $entry->delete();
            }
        }
    }

    /**
     * The payslip's data-driven components, as ledger lines.
     *
     * A component with no account anywhere is skipped rather than guessed at: posting
     * an allowance to the wrong account is worse than the imbalance that follows, and
     * the imbalance is caught immediately by the posting check.
     *
     * @return array<int, array{0: int, 1: float, 2: bool, 3: string}>
     */
    protected function dataDrivenLines(Payslip $payslip): array
    {
        $lines = [];

        $rows = $payslip->components()->with('component')->get()
            ->filter(fn ($row): bool => $row->component && ! $row->component->is_column_backed);

        foreach ($rows as $row) {
            $amount = round((float) $row->amount, 2);
            $accountId = $row->component->accountId();

            if ($amount <= 0 || ! $accountId) {
                continue;
            }

            $lines[] = [$accountId, $amount, $row->component->isEarning(), $row->component->label];
        }

        return $lines;
    }

    protected function accountId(string $key): int
    {
        return PayrollAccounts::id($key);
    }

    /**
     * First day of the payslip month inside its fiscal year.
     */
    protected function entryDate(Payslip $payslip): string
    {
        $fiscalYear = $payslip->fiscalYear;

        if (! $fiscalYear || ! $fiscalYear->start_date) {
            return now()->toDateString();
        }

        $startYear = $fiscalYear->start_date->year;
        $date = Carbon::parse("{$payslip->month} 1, {$startYear}");

        if ($date->lt($fiscalYear->start_date)) {
            $date->addYear();
        }

        return $date->toDateString();
    }
}
