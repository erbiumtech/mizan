<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipService;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The payslip PDF showing the payslip as it stands.
 *
 * It did not. Both places that produced one wrote a file on the first download and
 * served that file for ever after — `if (! Storage::exists($name))` — so a payslip
 * corrected afterwards went on handing out the figures it had before. The device
 * allowance paid as 1.00 instead of 5,000 was corrected in the system and the
 * employee's PDF still said 1.00.
 *
 * Worse on the API, whose file name was built from a `pay_period` attribute that
 * does not exist: the month was simply missing, so every month of a fiscal year
 * resolved to one file per employee and August's download was July's payslip.
 */
class PayslipPdfFreshnessTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'pdf@test.local'));
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'printed@test.local')->id,
            'employee_id' => 'EMP-PDF',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 400000,
            'device_allowance' => 5000,
        ]);
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

    private function service(): PayslipService
    {
        return app(PayslipService::class);
    }

    /**
     * The case that was paid and printed: an allowance overridden to 1.00, then
     * corrected. The PDF has to follow.
     */
    public function test_a_corrected_payslip_prints_the_correction(): void
    {
        $payslip = $this->payslip(attributes: ['device_allowance' => 1]);

        $before = $this->service()->renderPdf($payslip)->html();
        $this->assertStringContainsString('1.00', $before);

        $payslip->update(['device_allowance' => 0]);

        $this->assertSame(5000.0, (float) $payslip->fresh()->device_allowance);

        $after = $this->service()->renderPdf($payslip->fresh())->html();
        $this->assertStringContainsString('5,000.00', $after);
    }

    public function test_the_file_name_names_the_month(): void
    {
        // Without it, every month of a fiscal year was the same file.
        $july = $this->payslip('July');
        $august = $this->payslip('August');

        $this->assertSame('EMP-PDF-July-2026-2027.pdf', $this->service()->pdfFilename($july));
        $this->assertSame('EMP-PDF-August-2026-2027.pdf', $this->service()->pdfFilename($august));
    }

    public function test_nothing_is_written_to_disk(): void
    {
        // There is no file to go stale, which is the whole of the fix.
        $payslip = $this->payslip();

        $this->service()->renderPdf($payslip)->html();

        $this->assertNull($payslip->fresh()->pdf_path);
    }

    public function test_a_path_cannot_be_stored_on_a_payslip(): void
    {
        // Removed from fillable, so no future caller can quietly reintroduce the
        // promise that a file matches the figures.
        $payslip = $this->payslip();

        $payslip->update(['pdf_path' => 'payslips/stale.pdf']);

        $this->assertNull($payslip->fresh()->pdf_path);
    }

    // ---- The employee's own API --------------------------------------------

    public function test_the_api_points_at_the_download_route(): void
    {
        $payslip = $this->payslip();

        $this->actingAs($this->employee->user);

        $this->getJson('/api/my-payslips')
            ->assertOk()
            ->assertJsonPath('data.0.pdf_url', route('payslips.pdf', ['payslip' => $payslip->id]));
    }

    public function test_the_employee_can_download_their_own(): void
    {
        $payslip = $this->payslip();

        $this->actingAs($this->employee->user);

        $this->get(route('payslips.pdf', ['payslip' => $payslip->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * The id is in the URL. Without the check an employee reads a colleague's
     * salary by changing a number.
     */
    public function test_one_employee_cannot_download_anothers(): void
    {
        $other = Employee::create([
            'user_id' => $this->makeUser('Employee', 'colleague@test.local')->id,
            'employee_id' => 'EMP-OTHER',
            'gender' => 'Male',
            'phone' => '0300-0000001',
        ]);

        EmployeeSetting::create([
            'employee_id' => $other->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 900000,
        ]);

        $theirs = Payslip::create([
            'employee_id' => $other->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);

        $this->actingAs($this->employee->user);

        $this->get(route('payslips.pdf', ['payslip' => $theirs->id]))->assertForbidden();
    }

    public function test_listing_renders_no_pdfs(): void
    {
        // It used to render one for every payslip that had no file, so the first
        // call of a new fiscal year rendered a year of them before answering.
        foreach (['July', 'August', 'September'] as $month) {
            $this->payslip($month);
        }

        $this->actingAs($this->employee->user);

        $this->getJson('/api/my-payslips')->assertOk()->assertJsonCount(3, 'data');

        $this->assertSame(0, Payslip::whereNotNull('pdf_path')->count());
    }
}
