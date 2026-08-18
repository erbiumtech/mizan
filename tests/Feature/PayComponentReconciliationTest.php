<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Console\Commands\VerifyPayComponents;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipComponent;
use App\Modules\Payroll\Services\ComponentReconciliation;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Do the recorded pay components still add up to the payslips?
 *
 * `docs/akaunting-gap-plan.md` item 9 gates retiring the eleven column-backed components on
 * one condition: *"every existing payslip's gross and net are identical before and after."* The
 * backfill migration checked that once, in August 2026, and threw if it failed. Nothing has
 * checked it since — so for any company's real payroll history the answer has been unknown.
 *
 * `PayComponentRecorder` copies the columns into components on every save, so anything saved
 * through the model agrees by construction. Every test here therefore has to break that
 * agreement the way production breaks it: **behind the model**, with a raw update. Those are
 * the cases that matter, because they are the only ones that can happen —
 *
 *  - a figure corrected with a raw `UPDATE`, which is how a bad payslip gets fixed at 11pm;
 *  - a component row deleted after it was paid;
 *  - a payslip left by an older version of the calculation, or a restore from a backup taken
 *    mid-migration.
 *
 * Each leaves the components and the stored totals disagreeing with nothing announcing it, and
 * since the billing statement now reads components, a drift here mis-bills a client.
 */
class PayComponentReconciliationTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    private ComponentReconciliation $reconciliation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'reconcile@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->seed(\Database\Seeders\PayComponentSeeder::class);

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'reconciled@test.local')->id,
            'employee_id' => 'EMP-1',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 400000,
            'medical_allowance' => 20000,
        ]);

        $this->reconciliation = app(ComponentReconciliation::class);
    }

    private function payslip(string $month = 'July', array $attributes = []): Payslip
    {
        return Payslip::create(array_merge([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => $month,
            'total_working_days' => 22,
            'paid_days' => 22,
        ], $attributes));
    }

    /** Behind the model, which is the only way production breaks this. */
    private function rawUpdate(Payslip $payslip, array $attributes): void
    {
        Payslip::withoutEvents(fn () => Payslip::query()->whereKey($payslip->getKey())->update($attributes));
    }

    // ------------------------------------------------------------- the clean case

    public function test_a_payslip_saved_normally_reconciles(): void
    {
        $this->payslip();

        $this->assertTrue($this->reconciliation->discrepancies()->isEmpty());
    }

    public function test_a_payslip_with_allowances_and_deductions_reconciles(): void
    {
        $this->payslip('July', [
            'bonus' => 15000,
            'meal_deduction' => 3250,
            'esi_health_insurance' => 1200,
            'advances' => 5000,
        ]);

        $this->assertSame([], $this->reconciliation->discrepancies()->all());
    }

    /**
     * A reimbursement is paid with salary but is not in the gross, so counting it would report
     * every payslip carrying one as broken. That would be worse than not checking at all: a
     * verification that cries wolf gets switched off.
     */
    public function test_an_expense_reimbursement_does_not_read_as_a_discrepancy(): void
    {
        $this->payslip('July', ['expense_reimbursement' => 12000]);

        $this->assertTrue($this->reconciliation->discrepancies()->isEmpty());
    }

    /** A data-driven component is part of the gross and has to reconcile like any other. */
    public function test_a_data_driven_allowance_reconciles(): void
    {
        $component = PayComponent::create([
            'code' => 'housing_allowance',
            'label' => 'Housing Allowance',
            'kind' => PayComponent::KIND_EARNING,
            'account_key' => 'bonus_overtime',
        ]);

        \App\Modules\Payroll\Models\EmployeeSettingComponent::create([
            'employee_setting_id' => EmployeeSetting::where('employee_id', $this->employee->id)->firstOrFail()->getKey(),
            'pay_component_id' => $component->getKey(),
            'amount' => 40000,
        ]);

        $payslip = $this->payslip()->fresh();

        // 400,000 basic + 20,000 medical + 40,000 housing.
        $this->assertSame(460000.0, round((float) $payslip->total_earnings, 2));
        $this->assertTrue($this->reconciliation->discrepancies()->isEmpty());
    }

    // ---------------------------------------------------------------- the drift

    /** The 11pm fix: somebody raises the gross with an UPDATE and the parts no longer say why. */
    public function test_a_gross_raised_behind_the_model_is_reported(): void
    {
        $payslip = $this->payslip();

        $this->rawUpdate($payslip, ['total_earnings' => 445000]);

        $discrepancies = $this->reconciliation->discrepancies();

        $this->assertCount(1, $discrepancies);

        $row = $discrepancies->first();

        $this->assertSame($payslip->getKey(), $row['payslip_id']);
        $this->assertSame(445000.0, $row['stored_earnings']);
        $this->assertSame(420000.0, $row['component_earnings']);
        $this->assertSame(-25000.0, $row['earnings_difference']);
    }

    public function test_a_deduction_changed_behind_the_model_is_reported(): void
    {
        $payslip = $this->payslip('July', ['meal_deduction' => 3250]);

        $this->rawUpdate($payslip, ['total_deductions' => 99999]);

        $row = $this->reconciliation->discrepancies()->first();

        $this->assertNotNull($row);
        $this->assertSame(99999.0, $row['stored_deductions']);
        $this->assertNotSame(0.0, $row['deductions_difference']);
    }

    /** A component row deleted after it was paid: the money is on the payslip, unexplained. */
    public function test_a_deleted_component_row_is_reported(): void
    {
        $payslip = $this->payslip();

        $basic = PayComponent::where('code', 'basic_wage')->firstOrFail();

        PayslipComponent::where('payslip_id', $payslip->getKey())
            ->where('pay_component_id', $basic->getKey())
            ->delete();

        $row = $this->reconciliation->discrepancies()->first();

        $this->assertNotNull($row, 'a payslip missing its basic wage row reconciles, which it must not');
        $this->assertSame(-400000.0, $row['earnings_difference']);
    }

    /**
     * A payslip with no component rows at all — what a restore from a backup taken before the
     * backfill looks like. The whole gross is unexplained, which is the loudest case and the
     * one most likely to be met in practice.
     */
    public function test_a_payslip_with_no_components_at_all_is_reported(): void
    {
        $payslip = $this->payslip();

        PayslipComponent::where('payslip_id', $payslip->getKey())->delete();

        $row = $this->reconciliation->discrepancies()->first();

        $this->assertNotNull($row);
        $this->assertSame(0.0, $row['component_earnings']);
        $this->assertSame(-420000.0, $row['earnings_difference']);
    }

    /** Re-saving is the documented fix, so it had better actually work. */
    public function test_re_saving_a_drifted_payslip_repairs_it(): void
    {
        $payslip = $this->payslip();

        PayslipComponent::where('payslip_id', $payslip->getKey())->delete();
        $this->assertCount(1, $this->reconciliation->discrepancies());

        $payslip->fresh()->save();

        $this->assertTrue(
            $this->reconciliation->discrepancies()->isEmpty(),
            'the fix the command tells people to use does not work'
        );
    }

    // --------------------------------------------------------------- the command

    /**
     * `handle()` directly, not through `artisan()`.
     *
     * The command is `TenantAware`, and that trait's `execute()` iterates every tenant and
     * switches database for each — which this suite cannot do, running as it does against one
     * in-memory database. No existing test in this repository invokes a tenant-aware module
     * command through `artisan()` for exactly that reason. `handle()` is where the command's
     * own decisions live, so that is what is exercised; the tenant loop is the package's.
     *
     * @return array{0: int, 1: string} exit code, output
     */
    private function runCommand(bool $all = false): array
    {
        $command = new VerifyPayComponents;
        $command->setLaravel($this->app);

        $input = new ArrayInput(
            $all ? ['--all' => true] : [],
            new InputDefinition([new InputOption('all', null, InputOption::VALUE_NONE)]),
        );
        $buffer = new BufferedOutput;

        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $buffer));

        return [$command->handle(app(ComponentReconciliation::class)), $buffer->fetch()];
    }

    public function test_the_command_passes_on_a_clean_payroll(): void
    {
        $this->payslip();

        [$code, $output] = $this->runCommand();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('All 1 payslip(s) reconcile', $output);
    }

    /** Non-zero, so a deploy step or CI can gate the column retirement on it. */
    public function test_the_command_fails_and_names_the_payslip_when_something_drifts(): void
    {
        $payslip = $this->payslip();

        $this->rawUpdate($payslip, ['total_earnings' => 445000]);

        [$code, $output] = $this->runCommand();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('do not reconcile', $output);
        $this->assertStringContainsString((string) $payslip->getKey(), $output);

        // The fix, stated. A verification that reports a problem and not what to do about it
        // gets read once and ignored after.
        $this->assertStringContainsString('Re-saving a payslip', $output);
    }

    /**
     * An empty payroll must not read as a pass.
     *
     * Found by running the command for real: a tenant whose payroll has not been migrated, or
     * a mistyped `--tenant`, produced the same cheerful "everything reconciles" as a genuinely
     * clean history of forty-four payslips. Somebody would read that as a green light to retire
     * the columns. It now says what it actually checked.
     */
    public function test_an_empty_payroll_is_reported_as_not_a_pass(): void
    {
        [$code, $output] = $this->runCommand();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('nothing was verified', $output);
        $this->assertStringContainsString('not a pass', $output);
        $this->assertStringNotContainsString('reconcile:', $output);
    }

    public function test_the_all_option_lists_every_payslip_including_the_clean_ones(): void
    {
        $this->payslip('July');

        [$code, $output] = $this->runCommand(all: true);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('420000', $output, 'the listing does not show the figures');
    }

    /** Every payslip listed, reconciling or not, for the case where you want to see the set. */
    public function test_the_all_option_lists_payslips_that_reconcile_too(): void
    {
        $this->payslip('July');
        $this->payslip('August');

        $this->assertCount(2, $this->reconciliation->all());
        $this->assertTrue($this->reconciliation->discrepancies()->isEmpty());
    }
}
