<?php

namespace App\Modules\Payroll\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Payroll\Services\ComponentReconciliation;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Check that every payslip's recorded components add up to the payslip.
 *
 * The gate from `docs/akaunting-gap-plan.md` item 9 — *"every existing payslip's gross and net
 * are identical"* — as something somebody can actually run, against a real company, whenever
 * they want to know.
 *
 * **Not scheduled, deliberately.** A clean run is silent and a dirty one needs a person, so a
 * nightly version would either mail nobody anything for years or mail the same warning every
 * morning until somebody filtered it — the failure `CheckDocumentExpiry` was written to avoid.
 * This is a command you run when the answer matters: before retiring the columns, after
 * restoring a backup, or when a client queries a statement.
 *
 * Writes nothing. Exits non-zero when anything fails to reconcile, so CI or a deploy step can
 * gate on it.
 */
class VerifyPayComponents extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'payroll:verify-components {--tenant=*} {--all : List every payslip, not only the ones that disagree}';

    protected $description = "Check that each payslip's recorded pay components add up to its stored gross and deductions";

    public function handle(ComponentReconciliation $reconciliation): int
    {
        if ($this->skipsDisabledModule('payroll')) {
            return self::SUCCESS;
        }

        // Counted, because "everything reconciles" and "there is nothing here" must not read
        // the same. A verification that reports success over an empty table — a tenant whose
        // payroll has not been migrated, a --tenant typo — is worse than no verification, since
        // somebody would take it as a green light to retire the columns.
        $checked = $reconciliation->count();

        if ($checked === 0) {
            $this->warn('No payslips found, so nothing was verified. This is not a pass.');

            return self::SUCCESS;
        }

        if ($this->option('all')) {
            $rows = $reconciliation->all();

            $this->table(
                ['Payslip', 'Employee', 'Month', 'Gross', 'Components', 'Diff', 'Deductions', 'Components', 'Diff'],
                $rows->map(fn (array $row): array => [
                    $row['payslip_id'], $row['employee'], $row['month'],
                    $row['stored_earnings'], $row['component_earnings'], $row['earnings_difference'],
                    $row['stored_deductions'], $row['component_deductions'], $row['deductions_difference'],
                ])->all(),
            );
        }

        $discrepancies = $reconciliation->discrepancies();

        if ($discrepancies->isEmpty()) {
            $this->info("All {$checked} payslip(s) reconcile: their components add up to their stored gross and deductions.");

            return self::SUCCESS;
        }

        $this->error("{$discrepancies->count()} of {$checked} payslip(s) do not reconcile.");

        $this->table(
            ['Payslip', 'Employee', 'Month', 'Gross', 'Components', 'Diff', 'Deductions', 'Components', 'Diff'],
            $discrepancies->map(fn (array $row): array => [
                $row['payslip_id'], $row['employee'], $row['month'],
                $row['stored_earnings'], $row['component_earnings'], $row['earnings_difference'],
                $row['stored_deductions'], $row['component_deductions'], $row['deductions_difference'],
            ])->all(),
        );

        // What to do about it, because the figures alone do not say. Re-saving is the fix in
        // almost every case: PayComponentRecorder rebuilds the component rows from the
        // payslip's own columns, which are what the ledger and the payslip PDF were built from.
        $this->newLine();
        $this->warn('Re-saving a payslip rebuilds its components from its own figures. If a difference');
        $this->warn('survives that, the payslip itself disagrees with its parts and needs a person.');

        return self::FAILURE;
    }
}
