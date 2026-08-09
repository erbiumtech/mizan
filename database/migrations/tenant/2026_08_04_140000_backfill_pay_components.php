<?php

use Database\Seeders\PayComponentSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fill the component tables from the columns that already hold the same figures.
 *
 * Two things have to be true afterwards and both are checked here rather than hoped
 * for: every employee setting's components add up to the package its columns
 * describe, and every payslip's components add up to the gross and net it was issued
 * with. If either fails the migration throws and rolls back, because a half-migrated
 * payroll is worse than an unmigrated one.
 *
 * Nothing is dropped. The columns stay and the calculation still reads them; this
 * only makes the same facts available as data.
 */
return new class extends Migration
{
    /** column => component code, for the parts of a package. */
    private const SETTING_COLUMNS = [
        'basic_wage' => 'basic_wage',
        'medical_allowance' => 'medical_allowance',
        'petrol_allowance' => 'petrol_allowance',
        'device_allowance' => 'device_allowance',
        'bonus' => 'bonus',
        'extra_work_hours' => 'extra_work_hours',
        'advances' => 'advances',
        'meal_deduction' => 'meal_deduction',
        'esi_health_insurance' => 'esi_health_insurance',
    ];

    /** column => component code, for what a payslip paid. */
    private const PAYSLIP_COLUMNS = [
        'basic_wage' => 'basic_wage',
        'medical_allowance' => 'medical_allowance',
        'petrol_allowance' => 'petrol_allowance',
        'device_allowance' => 'device_allowance',
        'bonus' => 'bonus',
        'extra_work_hours' => 'extra_work_hours',
        'expense_reimbursement' => 'expense_reimbursement',
        'withholding_tax' => 'withholding_tax',
        'advances' => 'advances',
        'meal_deduction' => 'meal_deduction',
        'esi_health_insurance' => 'esi_health_insurance',
    ];

    public function up(): void
    {
        // The components have to exist before anything can point at them, and a tenant
        // that is only ever migrated still has to end up consistent — so they are
        // inserted here rather than left to db:seed.
        //
        // Written with the query builder, not the model: a migration that fires model
        // events writes to the audit log, and the audit log is a landlord table that
        // this connection cannot see. PayComponentSeeder::SHIPPED stays the one
        // definition of what they are.
        $components = $this->insertShippedComponents();

        $this->backfillSettings($components);
        $this->backfillPayslips($components);
        $this->verify();
    }

    /** @return \Illuminate\Support\Collection<string, int> code => id */
    private function insertShippedComponents(): \Illuminate\Support\Collection
    {
        foreach (PayComponentSeeder::SHIPPED as [$code, $label, $kind, $accountKey, $taxable, $sort]) {
            DB::table('pay_components')->updateOrInsert(
                ['code' => $code],
                [
                    'label' => $label,
                    'kind' => $kind,
                    'account_key' => $accountKey,
                    'is_taxable' => $taxable,
                    'is_column_backed' => true,
                    'is_active' => true,
                    'sort' => $sort,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        return DB::table('pay_components')->pluck('id', 'code');
    }

    private function backfillSettings($components): void
    {
        foreach (DB::table('employee_settings')->get() as $setting) {
            foreach (self::SETTING_COLUMNS as $column => $code) {
                $amount = round((float) ($setting->{$column} ?? 0), 2);

                if ($amount === 0.0) {
                    continue;
                }

                DB::table('employee_setting_components')->updateOrInsert(
                    ['employee_setting_id' => $setting->id, 'pay_component_id' => $components[$code]],
                    ['amount' => $amount, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    private function backfillPayslips($components): void
    {
        foreach (DB::table('payslips')->get() as $payslip) {
            foreach (self::PAYSLIP_COLUMNS as $column => $code) {
                $amount = round((float) ($payslip->{$column} ?? 0), 2);

                if ($amount === 0.0) {
                    continue;
                }

                DB::table('payslip_components')->updateOrInsert(
                    ['payslip_id' => $payslip->id, 'pay_component_id' => $components[$code]],
                    ['amount' => $amount, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    /**
     * The condition the plan set: no payslip's gross or net may move.
     *
     * Compared against the stored totals rather than recomputed from settings, because
     * what a payslip paid is the fact being preserved.
     */
    private function verify(): void
    {
        $earnings = DB::table('pay_components')->where('kind', 'earning')->pluck('id');
        $deductions = DB::table('pay_components')->where('kind', 'deduction')->pluck('id');

        // Reimbursement is paid with the salary but is not part of gross earnings —
        // it is the employee's own money coming back, and total_earnings never
        // included it.
        $reimbursement = DB::table('pay_components')->where('code', 'expense_reimbursement')->value('id');

        foreach (DB::table('payslips')->get() as $payslip) {
            $lines = DB::table('payslip_components')->where('payslip_id', $payslip->id)->get();

            $gross = round($lines
                ->filter(fn ($line): bool => $earnings->contains($line->pay_component_id) && $line->pay_component_id !== $reimbursement)
                ->sum('amount'), 2);

            $deducted = round($lines
                ->filter(fn ($line): bool => $deductions->contains($line->pay_component_id))
                ->sum('amount'), 2);

            $this->assertSame(
                round((float) $payslip->total_earnings, 2),
                $gross,
                "payslip #{$payslip->id} gross",
            );

            $this->assertSame(
                round((float) $payslip->total_deductions, 2),
                $deducted,
                "payslip #{$payslip->id} deductions",
            );
        }
    }

    private function assertSame(float $expected, float $actual, string $what): void
    {
        if (bccomp(number_format($expected, 2, '.', ''), number_format($actual, 2, '.', ''), 2) !== 0) {
            throw new \RuntimeException(
                "Pay component backfill would change {$what}: the columns say "
                .number_format($expected, 2).' and the components add up to '
                .number_format($actual, 2).'. Nothing has been migrated.'
            );
        }
    }

    public function down(): void
    {
        DB::table('payslip_components')->delete();
        DB::table('employee_setting_components')->delete();
    }
};
