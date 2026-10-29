<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\CsvImportService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Console\Commands\RemindPayslipAcknowledgements;
use App\Modules\Payroll\Models\EmployeeSettingComponent;
use App\Modules\Payroll\Models\PayAdjustment;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Payroll\Models\SalaryStructureLine;
use App\Modules\Payroll\Services\MonthlyPayrollService;
use App\Modules\Payroll\Services\PayslipService;
use App\Modules\Payroll\Services\SalaryStructures;
use App\Notifications\RecordChanged;
use App\Support\CsvImporters;
use App\Support\Pdf\DocumentBundle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The rest of §14.1 — structures, adjustments, the history import, the zip
 * and the reminders. Each claim is the smallest thing that would fail if the
 * machinery stopped doing what the plan says.
 */
class PayrollDepthTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $employeeUser;

    private Employee $employee;

    private EmployeeSetting $package;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-10 10:00:00');
        Notification::fake();
        config(['multitenancy.switch_tenant_tasks' => [\App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class]]);

        $this->actingAs($this->makeUser('Administrator', 'depth-admin@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();
        $this->seed([\Database\Seeders\TransactionTypeSeeder::class, \Database\Seeders\PayComponentSeeder::class]);

        $this->employeeUser = $this->makeUser('Employee', 'depth-emp@test.local');
        $this->employee = Employee::create([
            'user_id' => $this->employeeUser->id, 'employee_id' => 'EMP-D', 'name' => 'Dania', 'gender' => 'Female', 'is_active' => 1, 'bank_account_no' => '1',
        ]);
        $this->package = EmployeeSetting::create(['employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id, 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'basic_wage' => 100000]);
    }

    private function payComponent(string $code): PayComponent
    {
        return PayComponent::query()->where('code', $code)->firstOrFail();
    }

    // ───────────────────────────────────────────────────────── structures ──

    public function test_a_structure_fills_a_package_from_one_gross_figure(): void
    {
        $structure = SalaryStructure::create(['name' => 'Office staff']);
        $structure->lines()->createMany([
            ['pay_component_id' => $this->payComponent('basic_wage')->getKey(), 'mode' => SalaryStructureLine::MODE_BALANCE, 'value' => 0, 'sort' => 1],
            ['pay_component_id' => $this->payComponent('medical_allowance')->getKey(), 'mode' => SalaryStructureLine::MODE_SHARE, 'value' => 10, 'sort' => 2],
            ['pay_component_id' => $this->payComponent('petrol_allowance')->getKey(), 'mode' => SalaryStructureLine::MODE_FIXED, 'value' => 5000, 'sort' => 3],
        ]);

        $this->assertSame(['medical_allowance' => 12000.0, 'petrol_allowance' => 5000.0, 'basic_wage' => 103000.0], $structure->amountsFor(120000));

        $amounts = app(SalaryStructures::class)->apply($this->package, $structure, 120000);

        $this->assertSame(103000.0, (float) $this->package->fresh()->basic_wage);
        $this->assertSame(12000.0, (float) $this->package->fresh()->medical_allowance);
        $this->assertSame(5000.0, (float) $this->package->fresh()->petrol_allowance);
        $this->assertSame(0.0, (float) $this->package->fresh()->device_allowance, 'a component the structure does not name is left alone');
        $this->assertCount(3, $amounts);
    }

    public function test_a_data_driven_component_becomes_an_added_component(): void
    {
        $house = PayComponent::create(['code' => 'house_rent', 'label' => 'House rent', 'kind' => PayComponent::KIND_EARNING, 'basis' => 'fixed', 'account_key' => 'bonus_overtime', 'is_active' => true, 'sort' => 50]);
        $structure = SalaryStructure::create(['name' => 'Factory']);
        $structure->lines()->create(['pay_component_id' => $house->getKey(), 'mode' => SalaryStructureLine::MODE_SHARE, 'value' => 25]);

        app(SalaryStructures::class)->apply($this->package, $structure, 80000);

        $added = EmployeeSettingComponent::query()->where('employee_setting_id', $this->package->getKey())->where('pay_component_id', $house->getKey())->sole();
        $this->assertSame(20000.0, (float) $added->amount);

        app(SalaryStructures::class)->apply($this->package, $structure, 100000);
        $this->assertSame(25000.0, (float) $added->fresh()->amount, 'applied again, the row is updated rather than doubled');
    }

    // ──────────────────────────────────────────────────────── adjustments ──

    public function test_an_adjustment_reaches_the_input_store_when_the_month_is_raised_and_at_once_after(): void
    {
        PayAdjustment::create(['employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id, 'month' => 'August', 'pay_component_id' => $this->payComponent('bonus')->getKey(), 'amount' => 15000, 'reason' => 'Eid bonus']);

        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear);
        $payslip = Payslip::query()->where('month', 'August')->sole();

        $this->assertSame(15000.0, (float) $payslip->bonus);
        $this->assertSame(15000.0, (float) (app(PayslipService::class)->typedOverridesOf($payslip)['bonus'] ?? 0), 'written to the store, not typed onto the payslip');
        $this->assertSame($payslip->getKey(), (int) PayAdjustment::sole()->payslip_id);

        // A second one, after the payslip exists: the figures move at once, and the two add up.
        PayAdjustment::create(['employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id, 'month' => 'August', 'pay_component_id' => $this->payComponent('bonus')->getKey(), 'amount' => 5000, 'reason' => 'Target met']);
        $this->assertSame(20000.0, (float) $payslip->fresh()->bonus);

        // A deduction line, negative amounts, and nothing after sign-off.
        PayAdjustment::create(['employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id, 'month' => 'August', 'pay_component_id' => $this->payComponent('meal_deduction')->getKey(), 'amount' => 1200, 'reason' => 'Canteen']);
        $this->assertSame(1200.0, (float) $payslip->fresh()->meal_deduction);

        $accountant = $this->makeUser('Accountant', 'depth-acc@test.local');
        $this->assertTrue($accountant->can('update', PayAdjustment::first()));
        PayrollRun::forMonth('August', $this->fiscalYear)->lock(auth()->user());
        $this->assertFalse($accountant->can('update', PayAdjustment::first()), 'nobody edits an adjustment on a frozen month');

        try {
            PayAdjustment::create(['employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id, 'month' => 'August', 'pay_component_id' => $this->payComponent('bonus')->getKey(), 'amount' => 999, 'reason' => 'Too late']);
            $this->fail('An adjustment was written for a signed-off month.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('signed off', $e->getMessage());
        }

        $this->assertSame(20000.0, (float) $payslip->fresh()->bonus, 'a signed-off month does not move');
    }

    // ─────────────────────────────────────────────────────── history import ──

    public function test_salary_history_imports_as_paid_and_reverts_by_batch(): void
    {
        $this->assertTrue(CsvImporters::has('salary_history'));

        $csv = "employee_id,month,fiscal_year,basic_wage,medical_allowance,petrol_allowance,device_allowance,bonus,overtime,withholding_tax,advances,meal_deduction,esi_health_insurance,net_salary\n"
            ."EMP-D,July,{$this->fiscalYear->name},90000,9000,0,0,0,0,2500,0,0,0,96500\n"
            ."EMP-X,July,{$this->fiscalYear->name},1,0,0,0,0,0,0,0,0,0,1\n"
            ."EMP-D,Smarch,{$this->fiscalYear->name},1,0,0,0,0,0,0,0,0,0,1\n";

        $preview = app(CsvImportService::class)->preview($csv, 'salary_history');
        $this->assertSame(1, $preview['ready']);
        $this->assertSame(2, $preview['skipped']);

        $result = app(CsvImportService::class)->import($csv, 'salary_history');
        $this->assertSame(1, $result['imported']);

        $payslip = Payslip::query()->where('month', 'July')->sole();
        $this->assertSame(90000.0, (float) $payslip->basic_wage, 'as imported, not recalculated from the package');
        $this->assertSame(96500.0, (float) $payslip->net_salary);
        $this->assertSame(2500.0, (float) $payslip->withholding_tax, 'the tax as paid, whatever the slab says today');
        $this->assertStringStartsWith('hist-', $payslip->import_batch);
        $this->assertTrue($payslip->payrollRun->isLocked(), 'history is signed off on arrival');

        $this->artisan('payroll:revert-import', ['batch' => $payslip->import_batch, '--tenant' => [$this->tenant->getKey()]])
            ->expectsOutputToContain('1 payslip(s) from batch')
            ->assertSuccessful();
        $this->assertSame(0, Payslip::count());
        $this->assertSame(0, PayrollRun::where('month', 'July')->count(), 'the run the import made goes with it');
    }

    // ───────────────────────────────────────────────────── zip and reminders ──

    public function test_the_bundle_zips_one_file_per_payslip(): void
    {
        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear);
        $bundle = new DocumentBundle;

        foreach (Payslip::all() as $payslip) {
            $bundle->add('payslip-'.$payslip->getKey().'.pdf', '%PDF-1.4 test');
        }

        $this->assertSame(1, $bundle->count());
        $this->assertStringStartsWith('PK', $bundle->zip());
    }

    public function test_acknowledgement_reminders_stop_at_three(): void
    {
        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear);
        $payslip = Payslip::query()->sole();
        $payslip->forceFill(['sent_at' => now()->subDays(5)])->saveQuietly();

        for ($week = 1; $week <= 5; $week++) {
            $this->artisan('payroll:remind-acknowledgements', ['--tenant' => [$this->tenant->getKey()]])->assertSuccessful();
            Carbon::setTestNow(Carbon::now()->addWeek());
        }

        Notification::assertSentToTimes($this->employeeUser, RecordChanged::class, RemindPayslipAcknowledgements::MAX_REMINDERS);
        $this->assertSame(3, $payslip->fresh()->acknowledgement_reminders);

        // Acknowledged: nothing more, whatever the count.
        $payslip->forceFill(['employee_review' => Payslip::REVIEW_ACCEPTED, 'acknowledgement_reminders' => 0, 'last_reminded_at' => null])->saveQuietly();
        $this->artisan('payroll:remind-acknowledgements', ['--tenant' => [$this->tenant->getKey()]])->expectsOutputToContain('0 reminder(s)')->assertSuccessful();
    }
}
