<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Advances\Models\Advance;
use App\Modules\Advances\Services\AdvanceService;
use App\Modules\Billing\Models\BillingRun;
use App\Modules\Billing\Services\MonthlyBillingService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Payroll\Models\Payslip;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The month's bill to the client.
 *
 * This replaces a spreadsheet that was assembled by hand every month: a row per
 * employee at full cost, the office expenses under it, less what employees repaid
 * on their advances, converted at the month's rate. The tests are written against
 * the figures that spreadsheet produced.
 */
class MonthlyBillingTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contact $client;

    private MonthlyBillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'billing@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting', 'invoicing', 'advances', 'billing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
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
            'payable_type' => \App\Support\ModuleMap::alias(Contact::class),
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

    public function test_every_employee_with_a_payslip_gets_a_line(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000));
        $this->payslip($this->employee('Bilal Ahmed', 'EMP-2', 250000));

        $breakdown = $this->billing->breakdown($this->billingRun());

        $this->assertCount(2, $breakdown['salaries']);
        $this->assertSame('Salary — Ayesha Khan (EMP-1)', $breakdown['salaries'][0]['description']);
        $this->assertSame(650000.0, $breakdown['salary_total']);
    }

    /**
     * The client funds what the employee costs. Tax withheld and deductions taken
     * are settlements between the company and the employee, and billing the net
     * would leave the company short by the tax it has to hand over.
     */
    public function test_an_employee_is_billed_gross_not_net(): void
    {
        $employee = $this->employee('Ayesha Khan', 'EMP-1', 400000, [
            'medical_allowance' => 20000,
            'petrol_allowance' => 15000,
            'device_allowance' => 5000,
        ]);

        $payslip = $this->payslip($employee);

        $this->assertGreaterThan(0, (float) $payslip->withholding_tax, 'there is tax to withhold');
        $this->assertSame(440000.0, (float) $payslip->total_earnings);

        $breakdown = $this->billing->breakdown($this->billingRun());

        $this->assertSame(440000.0, $breakdown['salaries'][0]['amount']);
        $this->assertNotSame((float) $payslip->net_salary, $breakdown['salaries'][0]['amount']);
    }

    public function test_a_month_with_no_payslip_for_an_employee_leaves_them_off(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000), 'July');
        $this->payslip($this->employee('Bilal Ahmed', 'EMP-2', 250000), 'August');

        $breakdown = $this->billing->breakdown($this->billingRun(['month' => 'July']));

        $this->assertCount(1, $breakdown['salaries']);
        $this->assertStringContainsString('Ayesha Khan', $breakdown['salaries'][0]['description']);
    }

    public function test_office_expenses_are_grouped_by_kind(): void
    {
        // A month of small food payments should reach the client as one figure.
        $this->expense('food', 120000, '2026-07-05');
        $this->expense('food', 210000, '2026-07-20');
        $this->expense('rent', 92000, '2026-07-01');

        $breakdown = $this->billing->breakdown($this->billingRun());

        $this->assertCount(2, $breakdown['expenses']);
        $this->assertSame(
            [['Food', 330000.0], ['Rent', 92000.0]],
            array_map(fn ($line) => [$line['description'], $line['amount']], $breakdown['expenses']),
        );
        $this->assertSame(422000.0, $breakdown['expense_total']);
    }

    public function test_expenses_outside_the_month_are_left_out(): void
    {
        $this->expense('rent', 92000, '2026-07-01');
        $this->expense('rent', 92000, '2026-08-01');
        $this->expense('rent', 92000, '2026-06-30');

        $breakdown = $this->billing->breakdown($this->billingRun());

        $this->assertSame(92000.0, $breakdown['expense_total']);
    }

    /**
     * `value_date` and `recovered_on` are date casts and hold midnight, so an
     * upper bound of '2026-07-31' sorts before '2026-07-31 00:00:00'. The rent is
     * dated the last day of the month more often than not, and it went missing
     * with nothing to show it had.
     */
    public function test_the_last_day_of_the_month_is_in_the_month(): void
    {
        $employee = $this->employee('Mujahid Ali', 'EMP-1', 400000);

        Advance::create([
            'employee_id' => $employee->id,
            'total_amount' => 500000, 'monthly_instalment' => 50000,
            'started_on' => '2026-07-01', 'status' => Advance::STATUS_ACTIVE,
        ]);
        $this->payslip($employee);

        $this->expense('rent', 92000, '2026-07-31', 'Rent, last day');

        $breakdown = $this->billing->breakdown($this->billingRun());

        $this->assertSame(92000.0, $breakdown['expense_total'], 'the rent is billed');
        $this->assertSame(-50000.0, $breakdown['credit_total'], "and July's instalment is credited");
    }

    /**
     * A salary payment is the same money as the employee's own line. Billing both
     * would double the payroll — the largest number on the invoice.
     */
    public function test_salary_payments_are_not_billed_twice(): void
    {
        $employee = $this->employee('Ayesha Khan', 'EMP-1', 400000);
        $payslip = $this->payslip($employee);

        $salary = $this->expense('salary', 400000, '2026-07-31', 'Salary July');
        $salary->update(['payslip_id' => $payslip->id]);

        // And one typed in by hand, with no payslip behind it.
        $this->expense('salary', 50000, '2026-07-31', 'Salary top-up');

        $breakdown = $this->billing->breakdown($this->billingRun());

        $this->assertSame(0.0, $breakdown['expense_total']);
        $this->assertSame(400000.0, $breakdown['subtotal']);
    }

    /**
     * The advance left the company's bank when it was paid out and was billed to
     * the client as an expense then. As payroll recovers it, the client gets it
     * back — otherwise they fund the same money twice.
     */
    public function test_advance_repayments_come_off_as_a_credit(): void
    {
        $employee = $this->employee('Mujahid Ali', 'EMP-1', 400000);

        Advance::create([
            'employee_id' => $employee->id,
            'total_amount' => 1500000,
            'monthly_instalment' => 60000,
            'started_on' => '2026-07-01',
            'status' => Advance::STATUS_ACTIVE,
        ]);

        $payslip = $this->payslip($employee);
        $this->assertSame(60000.0, (float) $payslip->advances, 'payroll took the instalment');

        $breakdown = $this->billing->breakdown($this->billingRun());

        $this->assertCount(1, $breakdown['credits']);
        $this->assertSame(-60000.0, $breakdown['credits'][0]['amount']);
        $this->assertSame(340000.0, $breakdown['subtotal'], '400,000 gross less the 60,000 repaid');
    }

    public function test_a_repayment_recorded_by_hand_is_credited_too(): void
    {
        $employee = $this->employee('Mujahid Ali', 'EMP-1', 400000);

        $advance = Advance::create([
            'employee_id' => $employee->id,
            'total_amount' => 500000,
            'monthly_instalment' => 50000,
            'started_on' => '2026-07-01',
            'status' => Advance::STATUS_ACTIVE,
        ]);

        app(AdvanceService::class)->recordManualRecovery($advance, 200000, '2026-07-15', 'Cash returned');

        $breakdown = $this->billing->breakdown($this->billingRun());

        $this->assertSame(-200000.0, $breakdown['credits'][0]['amount']);
    }

    public function test_with_advances_switched_off_nothing_is_credited(): void
    {
        // Billing has to be sellable without the Advances module, and a client with
        // no advances simply has nothing to credit.
        $employee = $this->employee('Mujahid Ali', 'EMP-1', 400000);

        Advance::create([
            'employee_id' => $employee->id,
            'total_amount' => 500000, 'monthly_instalment' => 50000,
            'started_on' => '2026-07-01', 'status' => Advance::STATUS_ACTIVE,
        ]);
        $this->payslip($employee);

        CompanyModule::where('module', 'advances')->update(['enabled' => false]);
        modules()->flush();

        $breakdown = $this->billing->breakdown($this->billingRun());

        $this->assertSame([], $breakdown['credits']);
    }

    /** The spreadsheet this replaces, for July 2026, end to end. */
    public function test_the_month_adds_up_the_way_the_spreadsheet_did(): void
    {
        $salaries = [
            ['Mujahid Ali', 'EMP-1', 900000],
            ['Hammad Raza', 'EMP-2', 700000],
            ['Ayesha Khan', 'EMP-3', 400000],
        ];

        foreach ($salaries as [$name, $code, $wage]) {
            $employee = $this->employee($name, $code, $wage);

            if ($code !== 'EMP-3') {
                Advance::create([
                    'employee_id' => $employee->id,
                    'total_amount' => 1500000,
                    'monthly_instalment' => $code === 'EMP-1' ? 60000 : 70000,
                    'started_on' => '2026-07-01',
                    'status' => Advance::STATUS_ACTIVE,
                ]);
            }

            $this->payslip($employee);
        }

        $this->expense('rent', 92000, '2026-07-01', 'House rent');
        $this->expense('utilities', 35846, '2026-07-10', 'Electricity and water');
        $this->expense('food', 330000, '2026-07-25', 'Monthly food');

        $run = $this->billingRun();
        $breakdown = $this->billing->breakdown($run);

        $this->assertSame(2000000.0, $breakdown['salary_total']);
        $this->assertSame(457846.0, $breakdown['expense_total']);
        $this->assertSame(-130000.0, $breakdown['credit_total'], 'the two monthly instalments');
        $this->assertSame(2327846.0, $breakdown['subtotal']);

        $invoice = $this->billing->build($run);

        $this->assertSame(Invoice::KIND_SALE, $invoice->kind);
        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);
        $this->assertSame(7, $invoice->lines()->count(), '3 salaries + 3 expenses + 1 credit');
        $this->assertSame('2327846.00', $invoice->total);

        // What the client is asked for, at the rate agreed for the month.
        $this->assertSame(7390.0, round($run->fresh()->totalInClientCurrency(), 0));
    }

    public function test_building_links_the_invoice_to_the_run(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000));

        $run = $this->billingRun();
        $invoice = $this->billing->build($run);

        $this->assertSame($invoice->id, $run->fresh()->invoice_id);
        $this->assertSame($this->client->id, $invoice->contact_id);
        $this->assertStringContainsString('July 2026', $invoice->memo);
    }

    /**
     * A month is normally billed before the last expenses are entered, so
     * rebuilding has to replace the lines. Appending would bill everything already
     * on the invoice a second time.
     */
    public function test_rebuilding_replaces_the_lines_rather_than_adding_to_them(): void
    {
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000));

        $run = $this->billingRun();
        $invoice = $this->billing->build($run);

        $this->assertSame(1, $invoice->lines()->count());
        $this->assertSame('400000.00', $invoice->total);

        $this->expense('rent', 92000, '2026-07-01');

        $rebuilt = $this->billing->build($run->fresh());

        $this->assertSame($invoice->id, $rebuilt->id, 'the same invoice');
        $this->assertSame(2, $rebuilt->lines()->count());
        $this->assertSame('492000.00', $rebuilt->total);
    }

    public function test_an_issued_invoice_cannot_be_rebuilt(): void
    {
        // It has been posted to the ledger and sent; changing it would rewrite a
        // document the client is holding.
        $this->payslip($this->employee('Ayesha Khan', 'EMP-1', 400000));

        $run = $this->billingRun();
        $invoice = $this->billing->build($run);
        app(InvoiceService::class)->issue($invoice);

        $run = $run->fresh();
        $this->assertFalse($run->isRebuildable());

        $this->expectExceptionMessage('has already been issued and cannot be rebuilt');
        $this->billing->build($run);
    }

    public function test_an_empty_month_refuses_to_build(): void
    {
        $this->expectExceptionMessage('Nothing to bill for July 2026');

        $this->billing->build($this->billingRun());
    }

    /**
     * The credit reduces revenue, so it posts as a debit. As a negative credit it
     * would be dropped when the entry is assembled and the posting would fail the
     * balance check with nothing to point at.
     */
    public function test_a_bill_with_a_credit_line_posts_a_balanced_entry(): void
    {
        $employee = $this->employee('Mujahid Ali', 'EMP-1', 400000);

        Advance::create([
            'employee_id' => $employee->id,
            'total_amount' => 500000, 'monthly_instalment' => 60000,
            'started_on' => '2026-07-01', 'status' => Advance::STATUS_ACTIVE,
        ]);
        $this->payslip($employee);

        $invoice = $this->billing->build($this->billingRun());
        app(InvoiceService::class)->issue($invoice);

        $entry = $invoice->fresh()->journalEntry;

        $this->assertNotNull($entry);
        $this->assertSame(
            round((float) $entry->lines()->sum('debit_amount'), 2),
            round((float) $entry->lines()->sum('credit_amount'), 2),
        );
        $this->assertSame('340000.00', $invoice->fresh()->total);
    }

    public function test_a_client_cannot_be_billed_the_same_month_twice(): void
    {
        $this->billingRun();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->billingRun();
    }
}
