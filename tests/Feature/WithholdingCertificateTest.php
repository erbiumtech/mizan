<?php

namespace Tests\Feature;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\WithholdingCertificate;
use App\Modules\Payroll\Services\WithholdingTaxSummary;
use Tests\AccountingTestCase;

/**
 * The employee's certificate of tax deducted — the same rows, filter and figures
 * as the withholding summary and the FBR export, which is the property that
 * matters most: a certificate that disagreed with the statement filed against it
 * would be worse than no certificate, so the agreement is pinned behaviourally.
 */
class WithholdingCertificateTest extends AccountingTestCase
{
    use \Tests\Concerns\InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'wht-cert@test.local')->id,
            'employee_id' => 'EMP-CERT-1',
            'phone' => '0300-1111111', 'gender' => 'Male', 'is_active' => 1, 'nic' => '35201-1234567-1',
        ]);

        Payslip::withoutEvents(function (): void {
            // Created out of fiscal order on purpose: August first, so the
            // certificate's July-first ordering is proved, not inherited.
            foreach ([
                ['August', 250_000, 9_999],
                ['July', 250_000, 12_500],
                // Below the threshold: withheld nothing, appears nowhere — the
                // same exclusion the FBR export and the summary apply.
                ['September', 50_000, 0],
            ] as [$month, $earnings, $tax]) {
                Payslip::create([
                    'employee_id' => $this->employee->id, 'month' => $month,
                    'fiscal_year_id' => $this->fiscalYear->id,
                    'total_earnings' => $earnings, 'withholding_tax' => $tax,
                    'net_salary' => $earnings - $tax,
                ]);
            }
        });
    }

    private function data(): array
    {
        return app(WithholdingCertificate::class)->data($this->employee, $this->fiscalYear);
    }

    public function test_only_withholding_months_appear_in_fiscal_order(): void
    {
        $data = $this->data();

        $this->assertSame(['July', 'August'], array_column($data['rows'], 'month'));
        $this->assertSame(500_000.0, $data['taxable_total']);
        $this->assertSame(22_499.0, $data['tax_total']);
        $this->assertSame($this->fiscalYear->end_date->format('Y'), $data['tax_year']);
    }

    public function test_the_certificate_and_the_summary_can_never_disagree(): void
    {
        $summary = app(WithholdingTaxSummary::class)->summary($this->fiscalYear->id);
        $row = collect($summary['employees'])->firstWhere('employee_id', $this->employee->id);

        $data = $this->data();

        $this->assertSame($row['tax'], $data['tax_total']);
        $this->assertSame($row['taxable'], $data['taxable_total']);
    }

    public function test_it_refuses_what_it_cannot_certify(): void
    {
        $service = app(WithholdingCertificate::class);

        $this->employee->update(['nic' => null]);
        $missing = $service->missingFor($this->employee->fresh(), $this->fiscalYear);
        $this->assertTrue(collect($missing)->contains(fn (string $m): bool => str_contains($m, 'CNIC')));

        $nothingWithheld = Employee::create([
            'user_id' => $this->makeUser('Employee', 'wht-cert2@test.local')->id,
            'employee_id' => 'EMP-CERT-2', 'phone' => '0300-2222222',
            'gender' => 'Male', 'is_active' => 1, 'nic' => '35201-7654321-1',
        ]);
        $missing = $service->missingFor($nothingWithheld, $this->fiscalYear);
        $this->assertTrue(collect($missing)->contains(fn (string $m): bool => str_contains($m, 'certifies nothing')));
    }

    public function test_an_employee_downloads_their_own_certificate_from_the_payslips_page(): void
    {
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        // Sign in as the person the fixture's payslips belong to: the action is
        // self-only — the employee comes from the session, never from input.
        $this->actingAs($this->employee->user);
        $this->setCurrentTenant();

        // The letterhead facts the certificate refuses to issue without.
        $settings = app(\App\Support\TenantSettings::class);
        foreach (\App\Support\CompanyLetterhead::REQUIRED as $key => $label) {
            $settings->set("company.{$key}", 'Test '.$key);
        }

        \Livewire\Livewire::test(\App\Modules\Payroll\Filament\Resources\Payslips\Pages\ListPayslips::class)
            ->assertActionVisible('myWithholdingCertificate')
            ->callAction('myWithholdingCertificate', ['fiscal_year_id' => $this->fiscalYear->id])
            ->assertFileDownloaded();
    }

    public function test_a_user_who_is_not_an_employee_does_not_see_the_action(): void
    {
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        $this->actingAs($this->makeUser('Administrator', 'no-employee-record@test.local'));
        $this->setCurrentTenant();

        \Livewire\Livewire::test(\App\Modules\Payroll\Filament\Resources\Payslips\Pages\ListPayslips::class)
            ->assertActionHidden('myWithholdingCertificate');
    }

    public function test_a_different_year_certifies_nothing(): void
    {
        $other = FiscalYear::create([
            'name' => '2099-2100', 'start_date' => '2099-07-01', 'end_date' => '2100-06-30', 'is_active' => false,
        ]);

        $this->assertSame([], app(WithholdingCertificate::class)->data($this->employee, $other)['rows']);
    }
}
