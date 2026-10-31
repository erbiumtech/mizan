<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Console\Commands\BackfillComponentInputs;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipComponentInput;
use App\Modules\Payroll\Services\PayslipService;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The Phase 3 backfill command — docs/payslip-component-input-plan.md.
 *
 * It fills the store from the columns for every existing payslip, and proves it moved no figure before it
 * commits. The dry run writes nothing; --apply commits only a clean tenant. The payslip here is created and
 * then has its store emptied, to stand in for one issued before Phase 3 existed.
 */
class BackfillComponentInputsCommandTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'backfill@test.local'));
        $this->setCurrentTenant();

        PayComponent::firstOrCreate(
            ['code' => 'device_allowance'],
            ['label' => 'Device allowance', 'kind' => PayComponent::KIND_EARNING, 'is_column_backed' => true],
        );

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'backfill-emp@test.local')->id,
            'employee_id' => 'EMP-BF',
            'gender' => 'Male',
            'is_active' => 1,
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 200000,
            'medical_allowance' => 20000,
            'device_allowance' => 5000,
        ]);
    }

    /** A payslip with a device override, then its store emptied — the pre-Phase-3 shape the backfill is for. */
    private function issuedPayslip(): Payslip
    {
        $payslip = Payslip::createWithInputs([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
            'lop_days' => 0,
        ], ['device_allowance' => 7000])->fresh();

        PayslipComponentInput::query()->delete();

        return $payslip;
    }

    /** @return array{0: int, 1: string} exit code and output */
    private function runBackfill(bool $apply): array
    {
        $command = new BackfillComponentInputs;
        $command->setLaravel($this->app);

        $input = new ArrayInput(
            $apply ? ['--apply' => true] : [],
            new InputDefinition([new InputOption('apply', null, InputOption::VALUE_NONE)]),
        );
        $buffer = new BufferedOutput;

        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $buffer));

        return [$command->handle(app(PayslipService::class)), $buffer->fetch()];
    }

    public function test_a_dry_run_reports_what_it_would_store_and_writes_nothing(): void
    {
        $this->issuedPayslip();

        [$code, $output] = $this->runBackfill(apply: false);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('1 override row(s)', $output);
        $this->assertStringContainsString('the store changes no figure', $output);
        $this->assertSame(0, PayslipComponentInput::count(), 'the dry run rolled back');
    }

    public function test_apply_stores_the_override_and_moves_no_figure(): void
    {
        $payslip = $this->issuedPayslip();
        $net = (float) $payslip->net_salary;

        [$code, $output] = $this->runBackfill(apply: true);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Backfilled 1 override row(s)', $output);

        $row = PayslipComponentInput::query()->with('component:id,code')->first();
        $this->assertNotNull($row);
        $this->assertSame('device_allowance', $row->component->code);
        $this->assertSame(7000.0, (float) $row->amount);

        // The gate: the issued figures are untouched.
        $this->assertSame($net, (float) $payslip->fresh()->net_salary);
        $this->assertSame(7000.0, (float) $payslip->fresh()->device_allowance);
    }

    /**
     * A column that disagrees with a fresh recompute — an early month's withholding-tax split, drifted once
     * the year filled in — is noted, not a reason to abort. The backfill writes input rows and leaves the
     * column as issued, so it must still store the override.
     */
    public function test_a_stale_column_is_noted_not_a_reason_to_abort(): void
    {
        $payslip = $this->issuedPayslip();

        // Behind the model, the way the real drift arose: the stored tax no longer matches a recompute.
        DB::table('payslips')->where('id', $payslip->id)
            ->update(['withholding_tax' => (float) $payslip->withholding_tax + 1234.56]);

        [$code, $output] = $this->runBackfill(apply: true);

        $this->assertSame(0, $code, 'a stale column does not abort the backfill');
        $this->assertStringContainsString('redistribute', $output);
        $this->assertSame(1, PayslipComponentInput::count(), 'the override was still stored');
    }

    public function test_an_empty_payroll_is_not_a_pass(): void
    {
        [$code, $output] = $this->runBackfill(apply: true);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('This is not a pass', $output);
    }
}
