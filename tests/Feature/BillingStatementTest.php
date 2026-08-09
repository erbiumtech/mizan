<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Billing\Models\BillingRun;
use App\Modules\Billing\Services\MonthlyBillingService;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Payroll\Models\Payslip;
use App\Support\ModuleMap;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The bill as the client reads it: a row per employee split into what makes up
 * their cost, the office expenses under it, the credits, and the conversion.
 *
 * The shape comes from the spreadsheet this replaced, which is why the columns
 * are what they are. The assertion that matters most is not the layout though —
 * it is that the statement and the invoice built from the same month are the same
 * money. A statement that reads well and bills a different figure from the
 * invoice beside it is worse than no statement.
 */
class BillingStatementTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contact $client;

    private Company $company;

    private MonthlyBillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'statement@test.local'));
        $this->company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting', 'invoicing', 'billing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->client = Contact::create([
            'name' => 'Erbium AG',
            'kind' => Contact::KIND_CUSTOMER,
            'is_active' => true,
        ]);

        $this->billing = app(MonthlyBillingService::class);
    }

    private function employee(string $name, string $code, float $basic, array $extras = []): Employee
    {
        $user = $this->makeUser('Employee', str($name)->slug().'@test.local');
        $user->update(['name' => $name]);

        $employee = Employee::create([
            'user_id' => $user->id,
            'employee_id' => $code,
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);

        EmployeeSetting::create(array_merge([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => $basic,
        ], $extras));

        return $employee;
    }

    private function payslip(Employee $employee, string $month = 'July'): Payslip
    {
        return Payslip::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => $month,
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }

    private function expense(string $typeCode, float $amount, string $date, string $details = 'Office'): Payment
    {
        $type = TransactionType::byCode($typeCode) ?? TransactionType::create([
            'name' => ucfirst($typeCode),
            'code' => $typeCode,
            'is_active' => true,
        ]);

        return Payment::create([
            'payable_type' => ModuleMap::alias(Contact::class),
            'payable_id' => $this->client->id,
            'transaction_type_id' => $type->id,
            'amount' => $amount,
            'value_date' => $date,
            'details' => $details,
            'status' => Payment::STATUS_APPROVED,
        ]);
    }

    private function billingRun(array $attributes = []): BillingRun
    {
        return BillingRun::create(array_merge([
            'contact_id' => $this->client->id,
            'month' => 'July',
            'fiscal_year_id' => $this->fiscalYear->id,
            'invoice_date' => '2026-08-01',
            'currency' => 'EUR',
            'exchange_rate' => 315,
        ], $attributes));
    }

    private function url(array $query = []): string
    {
        return route('billing.statement', [
            'company' => $this->company->slug,
            'run' => $this->billingRun()->getKey(),
            ...$query,
        ]);
    }

    // --- the figures --------------------------------------------------------

    public function test_an_employee_is_a_row_of_what_makes_up_their_cost(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000, [
            'medical_allowance' => 21000,
            'petrol_allowance' => 20000,
            'device_allowance' => 10000,
        ]));

        $statement = $this->billing->statement($this->billingRun());

        $this->assertCount(1, $statement['employees']);

        $row = $statement['employees'][0];

        $this->assertSame('Ayesha Khan', $row['name']);
        $this->assertSame(400000.0, $row['amounts']['basic_wage']);
        $this->assertSame(21000.0, $row['amounts']['medical_allowance']);
        $this->assertSame(20000.0, $row['amounts']['petrol_allowance']);
        $this->assertSame(10000.0, $row['amounts']['device_allowance']);

        // The row total is the payslip's gross, and the columns add across to it.
        $this->assertSame(451000.0, $row['total']);
        $this->assertSame($row['total'], round(array_sum($row['amounts']), 2));
    }

    public function test_the_columns_add_down_to_the_totals(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000, ['petrol_allowance' => 20000]));
        $this->payslip($this->employee('Bilal Ahmed', 'EMP-2', 250000, ['petrol_allowance' => 20000]));

        $statement = $this->billing->statement($this->billingRun());

        $this->assertSame(650000.0, $statement['column_totals']['basic_wage']);
        $this->assertSame(40000.0, $statement['column_totals']['petrol_allowance']);
        $this->assertSame(690000.0, $statement['salary_total']);
    }

    /**
     * The one that keeps the document honest. Two ways of laying out the same
     * month must come to the same money, or the client is holding a sheet that
     * disagrees with the invoice stapled to it.
     */
    public function test_the_statement_and_the_invoice_are_the_same_money(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000, ['medical_allowance' => 21000]));
        $this->payslip($this->employee('Bilal Ahmed', 'EMP-2', 250000));
        $this->expense('RENT', 92000, '2026-07-31');
        $this->expense('FOOD', 330000, '2026-07-15');

        $run = $this->billingRun();

        $statement = $this->billing->statement($run);
        $breakdown = $this->billing->breakdown($run);
        $invoice = $this->billing->build($run);

        $this->assertSame($breakdown['salary_total'], $statement['salary_total']);
        $this->assertSame($breakdown['expense_total'], $statement['expense_total']);
        $this->assertSame($breakdown['subtotal'], $statement['subtotal']);
        $this->assertSame(round((float) $invoice->total, 2), $statement['subtotal']);
    }

    /**
     * The invoice bills "Utilities"; the statement says what the utilities were.
     * The client queries a line by what it was for, and "Utilities 236,826" is not
     * something anybody can check. The two still have to add to the same figure.
     */
    public function test_the_statement_itemises_expenses_the_invoice_groups(): void
    {
        $this->expense('UTIL', 35846, '2026-07-10', 'Electricity, water and society charges');
        $this->expense('UTIL', 980, '2026-07-10', 'Gas');
        $this->expense('UTIL', 25000, '2026-07-05', 'Internet');

        $run = $this->billingRun();

        $statement = $this->billing->statement($run);
        $breakdown = $this->billing->breakdown($run);

        $this->assertSame(
            ['Internet', 'Electricity, water and society charges', 'Gas'],
            array_column($statement['expenses'], 'description'),
            'listed as bought, earliest first'
        );

        $this->assertSame(['UTIL'], array_column($breakdown['expenses'], 'description'), 'one line, named for the type');

        $this->assertSame(61826.0, $statement['expense_total']);
        $this->assertSame($breakdown['expense_total'], $statement['expense_total']);
    }

    public function test_the_client_figure_is_the_total_at_the_months_rate(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 315000));

        $statement = $this->billing->statement($this->billingRun(['exchange_rate' => 315]));

        $this->assertSame(1000.0, $statement['client_total']);
    }

    public function test_without_a_rate_there_is_no_client_figure(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 315000));

        $this->assertNull($this->billing->statement($this->billingRun(['exchange_rate' => null]))['client_total']);
    }

    // --- the columns shown --------------------------------------------------

    public function test_the_five_columns_of_the_clients_sheet_are_always_there(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000));

        $columns = array_keys($this->billing->statement($this->billingRun())['columns']);

        $this->assertSame([
            'basic_wage', 'extra_work_hours', 'petrol_allowance', 'medical_allowance', 'device_allowance',
        ], $columns);
    }

    public function test_a_bonus_month_grows_a_bonus_column(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000, ['bonus' => 50000]));

        $statement = $this->billing->statement($this->billingRun());

        $this->assertArrayHasKey('bonus', $statement['columns']);
        $this->assertSame(50000.0, $statement['column_totals']['bonus']);
        $this->assertSame(450000.0, $statement['salary_total']);
    }

    /**
     * Gross is what gets billed, so anything in it the named columns cannot
     * explain has to appear rather than quietly making the row not add up. A
     * payslip edited by hand is the everyday way this happens.
     */
    public function test_gross_the_columns_cannot_explain_shows_as_other(): void
    {
        $payslip = $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000));

        // Written behind the model on purpose. Payslip recomputes its gross when
        // it is saved, so a row whose gross does not match its parts can only
        // arrive the way real ones do: edited in the database, or left by an older
        // version of the calculation.
        Payslip::withoutEvents(fn () => Payslip::query()
            ->whereKey($payslip->getKey())
            ->update(['total_earnings' => 425000]));

        $statement = $this->billing->statement($this->billingRun());

        $this->assertArrayHasKey('other', $statement['columns']);
        $this->assertSame(25000.0, $statement['employees'][0]['amounts']['other']);
        $this->assertSame(425000.0, $statement['employees'][0]['total']);
        $this->assertSame(425000.0, $statement['salary_total']);
    }

    // --- the page -----------------------------------------------------------

    public function test_the_statement_opens_as_a_page_of_its_own(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000, ['petrol_allowance' => 20000]));
        $this->expense('RENT', 92000, '2026-07-31', 'House rent');

        $response = $this->get($this->url())->assertOk();

        $response->assertSee('Ayesha Khan');
        $response->assertSee('Basic Salary');
        $response->assertSee('Petrol Allowance');
        $response->assertSee('Expenses');
        $response->assertSee('House rent');
        $response->assertSee(number_format(420000, 2));
        $response->assertSee('Download PDF');
    }

    public function test_the_pdf_opens_in_the_browser_by_default(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000));

        $response = $this->get($this->url(['format' => 'pdf']))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_pdf_can_be_asked_for_as_a_download(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000));

        $response = $this->get($this->url(['format' => 'pdf', 'download' => 1]))->assertOk();

        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('erbium-ag', (string) $response->headers->get('Content-Disposition'));
    }

    // --- who may read it ----------------------------------------------------

    /**
     * The page is outside the panel, so none of the panel's gates apply to it.
     * Everything below is the route's own doing.
     */
    public function test_an_employee_without_the_permission_is_refused(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000));
        $url = $this->url();

        $this->actingAs($this->makeUser('Employee', 'nosy@test.local'));

        $this->get($url)->assertForbidden();
    }

    public function test_someone_from_another_company_cannot_open_it(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000));
        $url = $this->url();

        $outsider = User::factory()->create(['status' => 1]);
        Company::factory()->create()->users()->attach($outsider);

        $this->actingAs($outsider);

        $this->get($url)->assertForbidden();
    }

    public function test_a_company_without_the_module_cannot_open_it(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000));
        $url = $this->url();

        CompanyModule::where('company_id', $this->company->getKey())
            ->where('module', 'billing')
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $this->get($url)->assertForbidden();
    }

    public function test_a_signed_out_visitor_is_sent_to_the_login_screen(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000));
        $url = $this->url();

        auth()->logout();

        $this->get($url)->assertRedirect();
    }
}
