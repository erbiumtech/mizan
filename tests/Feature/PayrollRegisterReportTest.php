<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Filament\Pages\PayrollRegister as PayrollRegisterPage;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayrollPostingService;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Database\Seeders\PayComponentSeeder;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The payroll register — `docs/reports-expansion-plan.md` Phase 2.1.
 *
 * Phase 2's rule is stated in the plan and it is the whole shape of this file: "each report's test asserts
 * the reconciliation — post entries, run the report, assert the record row equals the ledger balance for the
 * accounts behind it. A row-count assertion proves nothing here."
 *
 * So the tests below post real payroll entries and check the register against them, in three states: every
 * payslip posted and agreeing, a payslip left unposted, and a payslip altered behind the model after its
 * entry went in. The third is the one that matters most, because it is the only one that is a genuine fault
 * and the only one a reader could not have worked out for themselves.
 *
 * The rest of the file is about the grid: that a component nobody has since deleted still has a column, that
 * a dash is not a nought, and that the columns and rows add up to the record row — the property that lets
 * somebody check the report by adding it up, which is the only check most readers will ever perform.
 */
class PayrollRegisterReportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /** July of the seeded 2026-2027 fiscal year, so the report's month derivation lands on it. */
    private const AS_OF = '2026-07-20';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'register@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->seed(PayComponentSeeder::class);

        $this->employee = $this->makeEmployee('EMP-1', 'one@test.local');
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function makeEmployee(string $code, string $email, float $basic = 400_000, float $medical = 20_000): Employee
    {
        $employee = Employee::create([
            'user_id' => $this->makeUser('Employee', $email)->id,
            'employee_id' => $code,
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);

        EmployeeSetting::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => $basic,
            'medical_allowance' => $medical,
        ]);

        return $employee;
    }

    private function payslip(?Employee $employee = null, string $month = 'July', array $attributes = []): Payslip
    {
        return Payslip::create(array_merge([
            'employee_id' => ($employee ?? $this->employee)->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => $month,
            'total_working_days' => 22,
            'paid_days' => 22,
        ], $attributes));
    }

    /**
     * Post the payslip's entry for real, approving it if the company asks for a second approver.
     *
     * Named `bookEntry` rather than `post`: `Illuminate\Foundation\Testing\TestCase::post()` is the HTTP
     * helper and is public, so a private `post()` here is a fatal error rather than a shadowed method.
     */
    private function bookEntry(Payslip $payslip): void
    {
        $entry = app(PayrollPostingService::class)->postPayslip($payslip->fresh());

        if ($entry !== null && ! $entry->fresh()->is_posted) {
            $entry->update(['status' => \App\Modules\Accounting\Models\JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
            app(\App\Modules\Accounting\Services\JournalEntryService::class)->post($entry->fresh());
        }
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('PayrollRegister', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for PayrollRegister');

        return $payload;
    }

    private function column(array $payload, string $label): int
    {
        $index = array_search($label, $payload['columns'], true);

        $this->assertNotFalse($index, "no [{$label}] column in ".implode(' | ', $payload['columns']));

        return (int) $index;
    }

    private function figure(array $row, int $column): float
    {
        return (float) str_replace([',', '—'], ['', '0'], $row[$column]);
    }

    // ────────────────────────────────────────────────── the reconciliation ──

    /**
     * Net pay equals the credit to salaries payable, across a posted month.
     *
     * The claim Phase 2 exists for. Two employees, both posted: the register arrives at a net figure by
     * adding up component rows, and the ledger arrives at the same figure by a completely separate route —
     * the payslip entry crediting salaries payable. Nothing in between is shared.
     */
    public function test_net_pay_equals_the_credit_to_salaries_payable(): void
    {
        $this->bookEntry($this->payslip());
        $this->bookEntry($this->payslip($this->makeEmployee('EMP-2', 'two@test.local', 250_000, 10_000)));

        $payload = $this->report();

        $net = $payload['tiles'][0]['value'];
        $ledger = $payload['tiles'][1]['value'];

        $this->assertGreaterThan(0.0, $net, 'the fixture should produce some pay');
        $this->assertSame($net, $ledger, 'the register and the ledger disagree about net pay');
        $this->assertStringContainsString('NET PAY AGREES WITH SALARIES PAYABLE', $payload['note']);
    }

    /**
     * The ledger figure is this month's payslips, not the account's whole balance.
     *
     * Salaries payable carries every month's unpaid salaries and every payment made against them, so an
     * account *balance* would be neither independent of the register nor the same figure. This test exists
     * because mutating the source filter away — reading every posted credit to the account instead of the
     * credits from these payslips — left the first test above green: with one month of fixtures the two
     * readings are identical, which is exactly the kind of agreement that hides a bug until a company has
     * a second month of payroll.
     */
    public function test_the_ledger_figure_covers_only_the_month_being_read(): void
    {
        $july = $this->payslip(null, 'July');
        $this->bookEntry($july);

        $august = $this->payslip(null, 'August');
        $this->bookEntry($august);

        $julyNet = round((float) $july->fresh()->net_salary, 2);
        $augustNet = round((float) $august->fresh()->net_salary, 2);

        $this->assertGreaterThan(0.0, $julyNet);

        $julyReport = $this->report('2026-07-20');
        $this->assertSame($julyNet, $julyReport['tiles'][1]['value'], 'July must not carry August');
        $this->assertStringContainsString('AGREES', $julyReport['note']);

        $augustReport = $this->report('2026-08-20');
        $this->assertSame($augustNet, $augustReport['tiles'][1]['value'], 'August must not carry July');

        // And the account itself holds both, which is why its balance could not have been used.
        $this->assertNotSame($julyNet, round($julyNet + $augustNet, 2));
    }

    /**
     * An unposted payslip is named as the reason, not reported as a discrepancy.
     *
     * The everyday case: payroll is calculated before it is booked. A report that called this a difference
     * would cry wolf on the first day of every month, and a report that hid it would be wrong.
     */
    public function test_an_unposted_payslip_is_explained_rather_than_called_a_discrepancy(): void
    {
        $this->bookEntry($this->payslip());
        // Calculated, not booked.
        $this->payslip($this->makeEmployee('EMP-2', 'two@test.local', 250_000, 10_000));

        $payload = $this->report();

        $this->assertGreaterThan($payload['tiles'][1]['value'], $payload['tiles'][0]['value']);
        $this->assertStringContainsString('1 NOT POSTED', $payload['note']);
        $this->assertStringNotContainsString('DIFFER BY', $payload['note']);
    }

    /**
     * A payslip altered after its entry was posted *is* called a discrepancy.
     *
     * The only one of the three states that is a fault, and the one nobody could deduce from the screen.
     * Done behind the model with a raw update, because that is the only way production reaches it — saving
     * through the model would re-post the entry and the two would agree again.
     */
    public function test_a_payslip_changed_after_posting_is_reported_as_a_difference(): void
    {
        $payslip = $this->payslip();
        $this->bookEntry($payslip);

        // A correction typed straight into the database, which is how a bad payslip gets fixed at 11pm.
        Payslip::withoutEvents(fn () => Payslip::query()
            ->whereKey($payslip->getKey())
            ->update(['bonus' => 50_000, 'net_salary' => (float) $payslip->fresh()->net_salary + 50_000]));
        // And the component row alongside it, so the register sees the new figure.
        $bonus = PayComponent::where('code', 'bonus')->firstOrFail();
        $payslip->components()->updateOrCreate(['pay_component_id' => $bonus->getKey()], ['amount' => 50_000]);

        $payload = $this->report();

        $this->assertStringContainsString('DIFFER BY 50,000.00', $payload['note']);
        $this->assertStringContainsString('WITH EVERYTHING POSTED', $payload['note']);
    }

    /** A month with no payslips says so rather than reporting nought against nought. */
    public function test_a_month_with_no_payslips_says_so(): void
    {
        $payload = $this->report('2026-09-20');

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NO PAYSLIP EXISTS FOR THIS MONTH', $payload['note']);
        $this->assertStringContainsString('No payslip exists for September', $payload['empty']);
    }

    // ─────────────────────────────────────────────────────────── the grid ──

    /** Earnings less deductions equals net, per row — the arithmetic a reader checks first. */
    public function test_each_row_adds_up(): void
    {
        $this->payslip(null, 'July', ['bonus' => 15_000, 'meal_deduction' => 3_250, 'advances' => 5_000]);

        $payload = $this->report();
        $row = $payload['rows'][0];

        $earnings = $this->figure($row, $this->column($payload, 'Earnings'));
        $deductions = $this->figure($row, $this->column($payload, 'Deductions'));
        $net = $this->figure($row, $this->column($payload, 'Net'));

        $this->assertGreaterThan(0.0, $deductions, 'the fixture should produce some deductions');
        $this->assertSame($earnings - $deductions, $net);
    }

    /** And the record row adds up the rows above it, column by column. */
    public function test_the_record_row_totals_every_column(): void
    {
        $this->payslip();
        $this->payslip($this->makeEmployee('EMP-2', 'two@test.local', 250_000, 10_000));

        $payload = $this->report();

        // Every column but the first, which is the label.
        foreach (range(1, count($payload['columns']) - 1) as $column) {
            $rows = array_sum(array_map(fn (array $row): float => $this->figure($row, $column), $payload['rows']));

            $this->assertLessThanOrEqual(
                count($payload['rows']),
                abs($this->figure($payload['footer'], $column) - $rows),
                "column [{$payload['columns'][$column]}] does not add up the rows above it",
            );
        }
    }

    /**
     * A component deactivated after it was paid keeps its column.
     *
     * Reading only active components would take the amount out of the columns and leave it in the row
     * total, so the register would stop adding up — silently, and only in the months where somebody had
     * tidied the component list.
     */
    public function test_a_deactivated_component_keeps_its_column(): void
    {
        $this->payslip(null, 'July', ['bonus' => 15_000]);

        $this->assertContains('Bonus', $this->report()['columns']);

        PayComponent::where('code', 'bonus')->update(['is_active' => false]);

        $payload = $this->report();

        $this->assertContains('Bonus', $payload['columns'], 'a component paid this month must keep its column');
        // And the row still adds up, which is what the column is there to make true.
        $row = $payload['rows'][0];
        $this->assertSame(
            $this->figure($row, $this->column($payload, 'Earnings')) - $this->figure($row, $this->column($payload, 'Deductions')),
            $this->figure($row, $this->column($payload, 'Net')),
        );
    }

    /** A component that was never part of somebody's pay reads as a dash, not a nought. */
    public function test_a_component_nobody_paid_reads_as_a_dash(): void
    {
        $this->payslip();

        $payload = $this->report();
        $row = $payload['rows'][0];

        // No bonus in this fixture, so if the column exists at all its cell is a dash.
        $bonus = array_search('Bonus', $payload['columns'], true);

        if ($bonus !== false) {
            $this->assertSame('—', $row[$bonus]);
        }

        // The basic wage is definitely paid, and definitely not a dash.
        $this->assertNotSame('—', $row[$this->column($payload, 'Basic Salary')]);
    }

    /** Earnings before deductions, in the order a payslip is laid out. */
    public function test_earnings_columns_come_before_deductions(): void
    {
        $this->payslip(null, 'July', ['bonus' => 5_000, 'meal_deduction' => 1_000]);

        $payload = $this->report();

        $this->assertLessThan(
            $this->column($payload, 'Meal Deduction'),
            $this->column($payload, 'Basic Salary'),
            'an earning must not appear after a deduction',
        );
    }

    /**
     * One row per person, because the schema allows only one payslip each per month.
     *
     * `payslips` carries a unique key on (employee, month, fiscal year). That is what makes a row-per-
     * payslip register readable as a row-per-person one, and it is worth pinning here: if that key were
     * ever dropped, this report would silently start showing somebody twice with no total to say so.
     */
    public function test_a_person_can_only_have_one_payslip_in_a_month(): void
    {
        $this->payslip();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->payslip();
    }

    /** And two people are two rows, each named. */
    public function test_each_employee_gets_their_own_row(): void
    {
        $this->payslip();
        $this->payslip($this->makeEmployee('EMP-2', 'two@test.local', 250_000, 10_000));

        $payload = $this->report();
        $labels = array_column($payload['rows'], 0);

        $this->assertCount(2, $payload['rows']);
        $this->assertStringContainsString('EMP-1', implode(' ', $labels));
        $this->assertStringContainsString('EMP-2', implode(' ', $labels));
    }

    /** The month is derived from the date, and named on the report. */
    public function test_the_month_comes_from_the_date_and_is_named(): void
    {
        $this->payslip(null, 'July');
        $this->payslip(null, 'August');

        $july = $this->report('2026-07-20');
        $this->assertStringContainsString('July', $july['subtitle']);
        $this->assertCount(1, $july['rows']);

        $august = $this->report('2026-08-20');
        $this->assertStringContainsString('August', $august['subtitle']);
        $this->assertCount(1, $august['rows']);
    }

    /** Wide, because eleven components plus totals is more than the pane is wide. */
    public function test_the_register_declares_itself_wide(): void
    {
        $this->payslip();

        $this->assertTrue($this->report()['wide']);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->bookEntry($this->payslip());

        $onThePage = Livewire::test(PayrollRegisterPage::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('PayrollRegister', self::AS_OF, false, []));
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertTrue(PayrollRegisterPage::canAccess(), 'an administrator should be able to open it');

        $this->actingAs(\App\Modules\Core\Models\User::factory()->create(['status' => 1]));

        $this->assertFalse(PayrollRegisterPage::canAccess());
    }
}
