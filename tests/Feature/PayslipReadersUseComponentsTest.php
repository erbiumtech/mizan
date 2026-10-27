<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipComponent;
use App\Modules\Payroll\Services\PayrollPostingService;
use App\Modules\Payroll\Services\PayslipService;
use Database\Seeders\TransactionTypeSeeder;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The payslip PDF and the ledger read the component projection, not the columns — docs/payslip-component-input-plan.md Phase 5.
 *
 * The register and the billing statement already did; this moves the last two output readers, so what a
 * payslip paid has one source. Each test drifts a component below the model so the component and its column
 * disagree, then shows the reader followed the component — which a reader still on the column could not.
 */
class PayslipReadersUseComponentsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'readers@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->seed(TransactionTypeSeeder::class);
        Notification::fake();

        PayComponent::firstOrCreate(
            ['code' => 'device_allowance'],
            ['label' => 'Device allowance', 'kind' => PayComponent::KIND_EARNING, 'is_column_backed' => true],
        );

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'readers-emp@test.local')->id,
            'employee_id' => 'EMP-READ',
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

    private function payslip(): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
            'lop_days' => 0,
            'device_allowance' => 7000,
        ])->fresh();
    }

    /** Drift the device component below the model, so it disagrees with the 7,000 column. */
    private function driftDeviceComponent(Payslip $payslip, float $to): void
    {
        PayslipComponent::query()
            ->where('payslip_id', $payslip->id)
            ->whereHas('component', fn ($q) => $q->where('code', 'device_allowance'))
            ->update(['amount' => $to]);
    }

    public function test_the_pdf_shows_the_component_figure_not_the_column(): void
    {
        $payslip = $this->payslip();
        $this->driftDeviceComponent($payslip, 1234.56);

        $html = app(PayslipService::class)->renderPdf($payslip->fresh())->html();

        // The device line is the component's 1,234.56. A PDF still reading the 7,000 column would have shown
        // that and computed the line from it, so this figure appears only because the PDF read the component.
        $this->assertStringContainsString('1,234.56', $html, 'the PDF shows the component amount');
    }

    public function test_the_ledger_posts_the_component_figure_not_the_column(): void
    {
        $payslip = $this->payslip();

        // The device debit comes from the component; drift it and the debit side falls while net salary (a
        // column) does not, so the entry no longer balances. A ledger still reading the 7,000 column would
        // balance — the imbalance is the proof the figure came from the component.
        $this->driftDeviceComponent($payslip, 1000);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not balanced/');

        app(PayrollPostingService::class)->postPayslip($payslip->fresh());
    }
}
