<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipService;
use App\Support\TenantSettings;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The payslip names the company it belongs to.
 *
 * It did not. The template carried "ErbiumTech", "SMC-PRIVATE LIMITED", a Lahore street address, a phone
 * number and a website as literal text — so every company on the installation issued payslips on one
 * company's paper. The letterhead now comes from Company Settings, where the income certificate already reads
 * it, and a company that has not filled it in gets its own name rather than somebody else's.
 */
class PayslipLetterheadTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Payslip $payslip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'letterhead@test.local'));
        $this->setCurrentTenant();

        $employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'paid@test.local')->id,
            'employee_id' => 'EMP-LH',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);

        EmployeeSetting::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 400000,
        ]);

        $this->payslip = Payslip::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }

    private function html(): string
    {
        return app(PayslipService::class)->renderPdf($this->payslip)->html();
    }

    public function test_the_payslip_carries_the_letterhead_from_company_settings(): void
    {
        $settings = app(TenantSettings::class);
        $settings->set('company.legal_name', 'Karachi Widgets (Private) Limited');
        $settings->set('company.address', 'Plot 9, SITE Area, Karachi');
        $settings->set('company.phone', '+92 21 111 000 111');
        $settings->set('company.ntn', '9876543-2');

        $html = $this->html();

        $this->assertStringContainsString('Karachi Widgets (Private) Limited', $html);
        $this->assertStringContainsString('Plot 9, SITE Area, Karachi', $html);
        $this->assertStringContainsString('NTN 9876543-2', $html);
        $this->assertStringContainsString('Phone: +92 21 111 000 111', $html);

        // Not one trace of the company whose details used to be typed into the template.
        foreach (['ErbiumTech', 'SMC-PRIVATE', 'Khayaban', 'erbium.tech', '0606 888'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, "another company's letterhead leaked: {$leak}");
        }
    }

    /** A company that never opened the letterhead settings still gets its own name, never another's. */
    public function test_a_blank_letterhead_falls_back_to_the_tenants_own_name(): void
    {
        $html = $this->html();

        $this->assertStringContainsString($this->tenant->name, $html);
        $this->assertStringNotContainsString('ErbiumTech', $html);
        $this->assertStringNotContainsString('Khayaban', $html);
    }
}
