<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Projects\Models\Project;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Modules\Timesheets\Services\TimesheetService;
use App\Modules\Timesheets\Support\TimesheetReports;
use App\Support\Contracts\LabourCost;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Project margin: what a project was invoiced against what its hours cost in salary.
 *
 * The labour rate comes through the `LabourCost` contract from the payslip that paid for the hour, which is
 * the join this application never had — revenue knew its project, cost knew its department.
 */
class ProjectMarginTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $dev;

    private Project $project;

    private Contact $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'margin@test.local'));
        $this->setCurrentTenant();

        Notification::fake();

        foreach (['employees', 'payroll', 'projects', 'timesheets', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();

        $this->dev = Employee::create(['employee_id' => 'EMP-1', 'name' => 'Ali Raza', 'gender' => 'Male', 'is_active' => true]);

        EmployeeSetting::create([
            'employee_id' => $this->dev->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 150_000,
            'petrol_allowance' => 26_000,
        ]);

        // August 2026: 176,000 earned over 22 working days at eight hours — 1,000 an hour.
        Payslip::create([
            'employee_id' => $this->dev->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'August',
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);

        $this->client = Contact::create(['name' => 'Acme', 'kind' => 'customer']);
        $this->project = Project::create(['name' => 'Acme Portal', 'code' => 'ACME', 'contact_id' => $this->client->getKey()]);
    }

    private function book(float $hours, string $date, ?Project $project = null): void
    {
        TimesheetEntry::create([
            'employee_id' => $this->dev->id,
            'project_id' => ($project ?? $this->project)->getKey(),
            'date' => $date,
            'minutes' => (int) round($hours * 60),
            'is_billable' => true,
        ]);
    }

    private function invoice(string $kind, float $amount, string $date): Invoice
    {
        $invoice = Invoice::create([
            'kind' => $kind,
            'contact_id' => $this->client->getKey(),
            'project_id' => $this->project->getKey(),
            'invoice_date' => $date,
            'due_date' => $date,
            'subtotal' => $amount,
            'tax_amount' => 0,
            'total' => $amount,
        ]);

        $invoice->lines()->create(['description' => 'Work', 'quantity' => 1, 'unit_price' => $amount, 'line_total' => $amount] + (
            $kind === Invoice::KIND_PURCHASE ? ['account_id' => \App\Modules\Accounting\Models\Account::where('code', '5900')->value('id')] : []
        ));

        return app(InvoiceService::class)->issue($invoice);
    }

    public function test_an_hour_costs_what_the_payslip_paid_for_it(): void
    {
        $this->assertSame(1_000.0, app(LabourCost::class)->hourlyFor($this->dev->id, '2026-08-15'));

        // No payslip for September: nothing can say, and the report will show the hours as uncosted.
        $this->assertNull(app(LabourCost::class)->hourlyFor($this->dev->id, '2026-09-15'));
    }

    public function test_the_margin_is_revenue_less_labour_less_bills(): void
    {
        $this->book(10, '2026-08-05');
        $this->book(30, '2026-08-20');
        $this->invoice(Invoice::KIND_SALE, 120_000, '2026-08-31');
        $this->invoice(Invoice::KIND_PURCHASE, 15_000, '2026-08-10');

        $report = app(TimesheetService::class)->projectMargin('2026-07-01', '2026-08-31');
        $row = $report['projects'][0];

        $this->assertSame('Acme Portal', $row['project']->name);
        $this->assertSame(40.0, $row['hours']);
        $this->assertSame(40_000.0, $row['labour'], '40 hours at the 1,000 the August payslip paid per hour');
        $this->assertSame(15_000.0, $row['other_cost']);
        $this->assertSame(120_000.0, $row['revenue']);
        $this->assertSame(65_000.0, $row['margin']);
        $this->assertSame(54.2, $row['margin_percent']);
        $this->assertSame([], $report['uncosted']);
    }

    public function test_hours_with_no_payslip_are_counted_and_stated_but_not_priced(): void
    {
        $this->book(8, '2026-08-05');
        $this->book(8, '2026-09-05');

        $report = app(TimesheetService::class)->projectMargin('2026-07-01', '2026-09-30');
        $row = $report['projects'][0];

        $this->assertSame(16.0, $row['hours']);
        $this->assertSame(8.0, $row['uncosted_hours']);
        $this->assertSame(8_000.0, $row['labour'], 'only August is costed');
        $this->assertCount(1, $report['uncosted']);
        $this->assertStringContainsString('Sep 2026', $report['uncosted'][0]);
    }

    public function test_the_report_lays_it_out_with_a_footer_and_says_what_it_could_not_cost(): void
    {
        $this->book(10, '2026-08-05');
        $this->book(5, '2026-09-05');
        $this->invoice(Invoice::KIND_SALE, 50_000, '2026-08-31');

        $table = app(TimesheetReports::class)->projectMargin('2026-09-30');

        $this->assertSame('Project Margin', $table['title']);
        $this->assertSame(['Acme Portal', 'Acme', '15.0', '5.0', '10,000', '—', '50,000', '40,000', '80.0%'], $table['rows'][0]);
        $this->assertSame('40,000', $table['footer'][7]);
        $this->assertStringContainsString('5.0 HOURS HAVE NO PAYSLIP', $table['note']);
        $this->assertSame(40_000.0, $table['tiles'][2]['value']);
    }
}
