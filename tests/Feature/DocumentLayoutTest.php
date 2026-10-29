<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Settings\DocumentLayoutSettingsSection;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipService;
use App\Support\DocumentLayout;
use App\Support\TenantSettings;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A choice of layout and two lines of text — docs/saas-launch-plan.md §14.7.
 *
 * The plan is explicit that this is *not* free HTML, and the reason is the
 * per-engine override partials: markup typed by a company would go straight past
 * them and print differently under Dompdf than under Chromium. So what is tested
 * here is that the offered choices reach the paper, and that a company which has
 * never opened the screen gets the document it always had.
 */
class DocumentLayoutTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Payslip $payslip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'layout@test.local'));
        $this->setCurrentTenant();

        $employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'paid-layout@test.local')->id,
            'employee_id' => 'EMP-DL',
            'gender' => 'Female',
            'bank_account_no' => '0123456789',
            'iban_no' => 'PK36SCBL0000001123456702',
        ]);

        EmployeeSetting::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 300000,
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

    public function test_a_company_that_has_chosen_nothing_gets_the_payslip_it_always_had(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('PK36SCBL0000001123456702', $html);
        $this->assertStringContainsString('Total Working Days', $html);
        $this->assertStringContainsString('Employee Signature', $html);

        $this->assertTrue(DocumentLayout::payslipShows('bank'));
        $this->assertSame('', DocumentLayout::headerNote());
    }

    public function test_the_two_lines_of_wording_reach_the_paper(): void
    {
        $settings = app(TenantSettings::class);
        $settings->set('documents.header_note', 'Registered under the Companies Act 2017');
        $settings->set('documents.footer_note', 'Computer-generated; no signature required.');

        $html = $this->html();

        $this->assertStringContainsString('Registered under the Companies Act 2017', $html);
        $this->assertStringContainsString('Computer-generated; no signature required.', $html);
    }

    public function test_the_blocks_a_company_leaves_off_do_not_print(): void
    {
        app(TenantSettings::class)->set('documents.payslip_hidden', ['bank', 'attendance', 'signatures']);

        $html = $this->html();

        // The one that matters: a payslip is forwarded to a landlord more often than
        // people expect, and the account number is what nobody meant to send.
        $this->assertStringNotContainsString('PK36SCBL0000001123456702', $html);
        $this->assertStringNotContainsString('0123456789', $html);
        $this->assertStringNotContainsString('Total Working Days', $html);
        $this->assertStringNotContainsString('Employee Signature', $html);

        // Still a payslip: the figures are the point and they are never optional.
        $this->assertStringContainsString('Net Salary', $html);
        $this->assertStringContainsString('EMP-DL', $html);
    }

    public function test_the_settings_section_keeps_only_blocks_that_exist(): void
    {
        $section = new DocumentLayoutSettingsSection;

        $section->save([
            'documents_header_note' => ' A line ',
            'documents_footer_note' => '',
            'documents_payslip_hidden' => ['bank', 'nonsense'],
        ]);

        $this->assertSame(['bank'], setting('documents.payslip_hidden'),
            'A block name that is not one of ours is dropped rather than stored, so a renamed block '
            .'cannot leave a setting behind that silently hides nothing.');
        $this->assertSame('A line', DocumentLayout::headerNote());
        $this->assertSame('', DocumentLayout::footerNote());
        $this->assertSame(['documents_header_note' => ' A line ', 'documents_footer_note' => null, 'documents_payslip_hidden' => ['bank']], $section->fill());
    }
}
