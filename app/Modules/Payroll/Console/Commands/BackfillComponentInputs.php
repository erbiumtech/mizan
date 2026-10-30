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
 * **The store must not change what the calculation produces, and it proves that before it commits.** For every
 * payslip it syncs the store, then recomputes twice — once with the store ignored, once with it read — and the
 * two must agree to the cent. That is the exact Phase 3 invariant: moving the input onto the store changes no
 * figure. A tenant where it fails anywhere is rolled back whole and named; only a clean tenant is committed,
 * and only with `--apply`. The default is a dry run that writes nothing. Reversible either way: truncate
 * `payslip_component_inputs` and every read falls back to the columns.
 *
 * It deliberately does **not** gate on the stored column matching a fresh recompute. Those can differ for a
 * reason the store neither causes nor cures: the withholding tax is an annual figure split across the months,
 * so an early month issued before the year was complete carries a split a recompute today would redistribute —
 * same annual tax, different month. The backfill writes input rows only and never re-saves a column, so it
 * leaves that split exactly as issued. It counts the payslips in that state and prints the count as a note, so
 * whoever runs it sees the condition rather than having it hidden or mistaken for the store moving a figure.
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
        $staleTax = [];

        $connection->beginTransaction();

        foreach ($payslips as $payslip) {
            $typed = $service->columnOverridesOf($payslip);
            $attendance = $service->attendanceOf($payslip);
            $column = $this->figuresOf($payslip);

            $service->syncInputStore($payslip, $typed, $attendance);
            $rowsCreated += $payslip->componentInputs()->count();

            // The invariant: the store changes no figure. Recompute with it ignored and with it read; they
            // must agree. A difference means a stored row is doing something the columns did not.
            $withoutStore = $service->calculateByParams(
                $payslip->employee_id, $payslip->month, $payslip->fiscal_year_id,
                $typed, $payslip->id, $attendance, false,
            );
            $withStore = $service->calculateByParams(
                $payslip->employee_id, $payslip->month, $payslip->fiscal_year_id,
                $typed, $payslip->id, $attendance, true,
            );

            foreach (self::FIGURES as $key) {
                if (abs((float) ($withoutStore[$key] ?? 0) - (float) ($withStore[$key] ?? 0)) > 0.005) {
                    $drift[] = [$payslip->id, $payslip->month, $key, $withoutStore[$key] ?? 0, $withStore[$key] ?? 0];
                }

                // Separately: the stored column already disagrees with a fresh recompute. Pre-existing and
                // store-independent — noted, never a reason to abort, since the backfill leaves the column be.
                if (abs($column[$key] - (float) ($withoutStore[$key] ?? 0)) > 0.005) {
                    $staleTax[$payslip->id] = true;
                }
            }
        }

        if ($drift !== []) {
            $connection->rollBack();
            $this->error(count($drift).' figure(s) change when the store is read — nothing was written.');
            $this->table(['Payslip', 'Month', 'Figure', 'Without store', 'With store'], $drift);

            return self::FAILURE;
        }

        $note = $staleTax === []
            ? ''
            : ' '.count($staleTax).' payslip(s) carry a withholding-tax split a recompute would redistribute (pre-existing, untouched).';

        if ($apply) {
            $connection->commit();
            $this->info("Backfilled {$rowsCreated} override row(s) across {$payslips->count()} payslip(s); the store changes no figure.".$note);

            return self::SUCCESS;
        }

        $connection->rollBack();
        $this->info("Dry run: {$rowsCreated} override row(s) across {$payslips->count()} payslip(s) would be stored; the store changes no figure.".$note.' Re-run with --apply to commit.');

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
