<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Filament\Pages\TaxSummary;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\WithholdingTaxSummary;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Salary withholding, by employee and by month.
 *
 * The FBR export answers "what do we file this month". It could not answer "what
 * have we withheld from this person this year" — the question the employee asks and
 * the one a year-end reconciliation needs.
 *
 * The figures have to agree with the file filed against them, so taxable amount is
 * total earnings here exactly as it is there.
 */
class TaxSummaryTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'taxsummary@test.local'));
        $this->setCurrentTenant();
    }

    private function employee(string $name, float $wage): Employee
    {
        $user = $this->makeUser('Employee', str($name)->slug().'@test.local');
        $user->update(['name' => $name]);

        $employee = Employee::create([
            'user_id' => $user->id,
            'employee_id' => 'EMP-'.substr(md5($name), 0, 4),
            'gender' => 'Male',
            'phone' => '0300-0000000',
            'nic' => '35202-'.substr(md5($name), 0, 7).'-1',
        ]);

        EmployeeSetting::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => $wage,
        ]);

        return $employee;
    }

    private function payslip(Employee $employee, string $month): Payslip
    {
        return Payslip::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => $month,
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }

    private function summary(?string $month = null): array
    {
        return app(WithholdingTaxSummary::class)->summary($this->fiscalYear->id, $month);
    }

    public function test_it_totals_the_tax_withheld(): void
    {
        $employee = $this->employee('Umer Farooq', 500000);
        $july = $this->payslip($employee, 'July');
        $august = $this->payslip($employee, 'August');

        $expected = round((float) $july->withholding_tax + (float) $august->withholding_tax, 2);

        $this->assertGreaterThan(0, $expected, 'this wage is above the threshold');
        $this->assertSame($expected, $this->summary()['tax_total']);
    }

    public function test_an_employee_line_covers_their_whole_year(): void
    {
        $employee = $this->employee('Umer Farooq', 500000);
        $this->payslip($employee, 'July');
        $this->payslip($employee, 'August');

        $row = $this->summary()['employees'][0];

        $this->assertSame('Umer Farooq', $row['name']);
        $this->assertSame(2, $row['months']);
        $this->assertNotSame('', $row['nic'], 'the CNIC the FBR file files under');
    }

    public function test_the_taxable_figure_is_the_one_the_fbr_file_uses(): void
    {
        // Taxable_Amount in EmployeeWithholdingTaxExport is total_earnings. A summary
        // that disagreed with the return filed against it would be worse than none.
        $employee = $this->employee('Umer Farooq', 500000);
        $payslip = $this->payslip($employee, 'July');

        $this->assertSame(
            round((float) $payslip->total_earnings, 2),
            $this->summary()['taxable_total'],
        );
    }

    public function test_a_payslip_below_the_threshold_is_left_out(): void
    {
        // It withholds nothing and belongs on no return, which is how the FBR export
        // treats it too.
        $taxed = $this->employee('Umer Farooq', 500000);
        $untaxed = $this->employee('Arooj Fatima', 30000);

        $this->payslip($taxed, 'July');
        $untaxedSlip = $this->payslip($untaxed, 'July');

        $this->assertSame(0.0, (float) $untaxedSlip->withholding_tax);

        $names = array_column($this->summary()['employees'], 'name');

        $this->assertContains('Umer Farooq', $names);
        $this->assertNotContains('Arooj Fatima', $names);
    }

    public function test_months_come_in_fiscal_year_order(): void
    {
        // Not alphabetically, and not by whichever payslip was created first: a tax
        // summary is read in the order the months were paid.
        $employee = $this->employee('Umer Farooq', 500000);

        foreach (['August', 'July', 'January'] as $month) {
            $this->payslip($employee, $month);
        }

        $this->assertSame(
            ['July', 'August', 'January'],
            array_column($this->summary()['months'], 'month'),
        );
    }

    public function test_one_month_can_be_asked_for_on_its_own(): void
    {
        $employee = $this->employee('Umer Farooq', 500000);
        $july = $this->payslip($employee, 'July');
        $this->payslip($employee, 'August');

        $summary = $this->summary('July');

        $this->assertCount(1, $summary['months']);
        $this->assertSame(round((float) $july->withholding_tax, 2), $summary['tax_total']);
    }

    public function test_employees_are_ordered_by_what_they_paid(): void
    {
        $small = $this->employee('Arooj Fatima', 400000);
        $large = $this->employee('Umer Farooq', 900000);

        $this->payslip($small, 'July');
        $this->payslip($large, 'July');

        $this->assertSame('Umer Farooq', $this->summary()['employees'][0]['name']);
    }

    public function test_the_page_and_the_printable_version_render(): void
    {
        $employee = $this->employee('Umer Farooq', 500000);
        $this->payslip($employee, 'July');

        Livewire::test(TaxSummary::class)
            ->assertSee('Umer Farooq')
            ->assertSee('July');

        $url = route('reports.tax-summary', [
            'company' => $this->tenant->slug,
            'fiscal_year_id' => $this->fiscalYear->id,
        ]);

        $this->get($url)->assertOk()->assertSee('Tax Summary')->assertSee('Umer Farooq');
        $this->get($url.'&format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_an_untaxed_period_says_so(): void
    {
        Livewire::test(TaxSummary::class)->assertSee('No tax withheld in this period');
    }
}
