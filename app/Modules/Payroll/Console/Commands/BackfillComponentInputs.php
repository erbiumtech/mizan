<?php

namespace App\Modules\Payroll\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipService;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Move each payslip's genuine per-month overrides onto the input store — docs/payslip-component-input-plan.md
 * Phase 3, the hinge.
 *
 * The store is empty after Phase 2. This fills it from what the columns already hold, for every payslip, so
 * the typed input lives on the store a later phase reads rather than on the columns. A "genuine override" is a
 * figure no setting, ledger or claim would have produced — `PayslipService::syncInputStore` decides, by
 * comparing each column to the pure fallback — so a value that merely echoes a fallback is left off the store
 * and keeps following it.
 *
 * **It must not move a single figure, and it proves that before it commits.** For every payslip it snapshots
 * the fourteen computed figures, syncs the store, recomputes, and compares. A tenant with any drift is rolled
 * back whole and named; only a clean tenant is committed, and only with `--apply`. The default is a dry run
 * that writes nothing — the before/after check the plan asks for, run against the whole history. Reversible
 * either way: truncate `payslip_component_inputs` and every read falls back to the columns.
 *
 * One behaviour it is honest about: a column that has drifted from its live setting since the payslip was
 * issued — a setting changed, the payslip never re-saved — reads as an override and is frozen at its issued
 * figure. For a settled month that is correct (a paid payslip should not move); for an untouched draft it
 * means the field no longer tracks the setting until retyped. No figure moves either way, which is the gate.
 */
class BackfillComponentInputs extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'payroll:backfill-component-inputs {--tenant=*} {--apply : Commit the store rows; without it this is a dry run that writes nothing}';

    protected $description = "Move each payslip's genuine per-month overrides onto the input store, proving no figure moves";

    /** The fourteen figures a payslip stores and the calculation reproduces — the equality check's subject. */
    private const FIGURES = [
        'basic_wage', 'medical_allowance', 'device_allowance', 'petrol_allowance', 'bonus',
        'extra_work_hours', 'expense_reimbursement', 'withholding_tax', 'advances', 'meal_deduction',
        'esi_health_insurance', 'total_earnings', 'total_deductions', 'net_salary',
    ];

    public function handle(PayslipService $service): int
    {
        if ($this->skipsDisabledModule('payroll')) {
            return self::SUCCESS;
        }

        $payslips = Payslip::query()->orderBy('id')->get();

        // Counted, because "nothing drifted" and "there was nothing here" must not read the same — a backfill
        // that reports success over an empty table would be taken as a green light the history never earned.
        if ($payslips->isEmpty()) {
            $this->warn('No payslips found, so nothing was backfilled. This is not a pass.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $connection = $payslips->first()->getConnection();
        $rowsCreated = 0;
        $drift = [];

        $connection->beginTransaction();

        foreach ($payslips as $payslip) {
            $before = $this->figuresOf($payslip);

            $service->syncInputStore($payslip, $service->typedOverridesOf($payslip), $service->attendanceOf($payslip));
            $rowsCreated += $payslip->componentInputs()->count();

            $after = $service->calculateByParams(
                $payslip->employee_id, $payslip->month, $payslip->fiscal_year_id,
                $service->typedOverridesOf($payslip), $payslip->id, $service->attendanceOf($payslip),
            );

            foreach (self::FIGURES as $key) {
                if (abs($before[$key] - (float) ($after[$key] ?? 0)) > 0.005) {
                    $drift[] = [$payslip->id, $payslip->month, $key, $before[$key], $after[$key] ?? 0];
                }
            }
        }

        if ($drift !== []) {
            $connection->rollBack();
            $this->error(count($drift).' figure(s) would move — nothing was written.');
            $this->table(['Payslip', 'Month', 'Figure', 'Before', 'After'], $drift);

            return self::FAILURE;
        }

        if ($apply) {
            $connection->commit();
            $this->info("Backfilled {$rowsCreated} override row(s) across {$payslips->count()} payslip(s); no figure moved.");

            return self::SUCCESS;
        }

        $connection->rollBack();
        $this->info("Dry run: {$rowsCreated} override row(s) across {$payslips->count()} payslip(s) would be stored; no figure moves. Re-run with --apply to commit.");

        return self::SUCCESS;
    }

    /** @return array<string, float> */
    private function figuresOf(Payslip $payslip): array
    {
        $out = [];

        foreach (self::FIGURES as $key) {
            $out[$key] = (float) $payslip->{$key};
        }

        return $out;
    }
}
