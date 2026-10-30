<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\AnnualTax;
use App\Modules\Payroll\Models\EmployeeSettingComponent;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * B24: the annual tax record projects the same income the payslips withhold on.
 *
 * The payslip's own projection adds a package's taxable components for every month not yet paid; the
 * AnnualTax record added only the column-backed pay, so it understated the income — and the tax — of anybody
 * with an allowance kept as a component.
 */
class AnnualTaxProjectionTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'annual-tax@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();
        $this->seed(\Database\Seeders\PayComponentSeeder::class);

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'annual-tax-emp@test.local')->id, 'employee_id' => 'EMP-AT', 'gender' => 'Male',
        ]);

        $setting = EmployeeSetting::create([
            'employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'basic_wage' => 100000,
        ]);

        $internet = PayComponent::create(['code' => 'internet_allowance', 'label' => 'Internet', 'kind' => PayComponent::KIND_EARNING, 'is_taxable' => true, 'account_key' => 'bonus_overtime']);
        $reimbursement = PayComponent::create(['code' => 'phone_refund', 'label' => 'Phone refund', 'kind' => PayComponent::KIND_EARNING, 'is_taxable' => false, 'account_key' => 'bonus_overtime']);

        EmployeeSettingComponent::create(['employee_setting_id' => $setting->id, 'pay_component_id' => $internet->id, 'amount' => 10000]);
        EmployeeSettingComponent::create(['employee_setting_id' => $setting->id, 'pay_component_id' => $reimbursement->id, 'amount' => 2000]);
    }

    public function test_the_projection_counts_the_packages_taxable_components_for_the_months_not_yet_paid(): void
    {
        $july = Payslip::create([
            'employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id, 'month' => 'July',
            'total_working_days' => 22, 'paid_days' => 22,
        ])->fresh();

        $record = AnnualTax::where('employee_id', $this->employee->id)->where('fiscal_year_id', $this->fiscalYear->id)->firstOrFail();

        // July as paid, then eleven months of basic plus the taxable internet allowance — the untaxed refund is not
        // income the projection counts.
        $this->assertSame(round((float) $july->total_earnings + 11 * (100000 + 10000), 2), (float) $record->total_annual_income);
    }
}
