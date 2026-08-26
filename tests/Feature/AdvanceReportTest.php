<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Advances\Filament\Pages\AdvancesOutstanding;
use App\Modules\Advances\Models\Advance;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Advances Outstanding — `docs/reports-expansion-plan.md` Phase 2.7.
 *
 * Three things are worth asserting and one is not.
 *
 * Worth asserting: that the report's shortcut for the recovered figure agrees with the model's own method;
 * that the comparison against the advances account names *which way round* the difference falls, because the
 * two directions mean opposite things; and that a company without accounting still gets the register.
 *
 * Not worth asserting: that the register and the account agree. They usually will not, because nothing posts
 * an advance when it is entered — so a test demanding agreement would be testing a fixture rather than the
 * application. What is tested instead is that the report explains the difference correctly in both
 * directions.
 */
class AdvanceReportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const AS_OF = '2026-08-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'advances@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting', 'advances'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function employee(string $code): Employee
    {
        return Employee::create([
            'employee_id' => $code,
            'name' => $code,
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);
    }

    private function advance(Employee $employee, float $total, float $instalment, array $attributes = []): Advance
    {
        return Advance::create(array_merge([
            'employee_id' => $employee->id,
            'total_amount' => $total,
            'monthly_instalment' => $instalment,
            'started_on' => '2026-07-01',
            'status' => Advance::STATUS_ACTIVE,
        ], $attributes));
    }

    private function recover(Advance $advance, float $amount, string $on = '2026-07-31'): void
    {
        $advance->recoveries()->create(['amount' => $amount, 'recovered_on' => $on]);
    }

    /** Post a debit to the advances account, which is what booking a disbursement looks like. */
    private function bookDisbursement(float $amount, string $on = '2026-07-01'): void
    {
        $entry = app(JournalEntryService::class)->create(
            ['entry_date' => $on, 'entry_type' => 'general', 'memo' => 'Advance paid'],
            [
                ['account_id' => Account::where('code', '1200')->firstOrFail()->id, 'debit_amount' => $amount],
                ['account_id' => Account::where('code', '1100')->firstOrFail()->id, 'credit_amount' => $amount],
            ],
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        app(JournalEntryService::class)->post($entry->fresh());
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('AdvancesOutstanding', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for AdvancesOutstanding');

        return $payload;
    }

    private function row(array $payload, string $code): array
    {
        foreach ($payload['rows'] as $row) {
            if (str_contains($row[0], $code)) {
                return $row;
            }
        }

        $this->fail("no row for [{$code}] in ".collect($payload['rows'])->flatten()->implode(' | '));
    }

    // ────────────────────────────────────────────────────── the shortcut ──

    /**
     * The report's recovered figure equals the model's own, advance by advance.
     *
     * The report sums the loaded relation to avoid a query per row, and with no payslip to exclude that is
     * exactly what `recoveredAmount()` computes. Asserted rather than assumed, because a `where` added to
     * the `recoveries()` relation would part the two silently — and the outstanding figure is what a final
     * settlement is checked against.
     */
    public function test_the_reports_recovered_figure_matches_the_models(): void
    {
        $one = $this->advance($this->employee('EMP-1'), 120_000, 10_000);
        $this->recover($one, 10_000);
        $this->recover($one, 5_000, '2026-08-31');

        $two = $this->advance($this->employee('EMP-2'), 60_000, 20_000);
        $this->recover($two, 20_000);

        $payload = $this->report();

        foreach ([$one, $two] as $advance) {
            $row = $this->row($payload, $advance->employee->employee_id);

            $this->assertSame(
                number_format($advance->fresh()->recoveredAmount(), 0),
                $row[2],
                'the report and the model disagree about what has been recovered',
            );
            $this->assertSame(
                number_format($advance->fresh()->remainingAmount(), 0),
                $row[3],
                'and therefore about what is outstanding',
            );
        }
    }

    /** And it is one query for the register, not one per advance. */
    public function test_it_does_not_query_per_advance(): void
    {
        foreach (range(1, 10) as $i) {
            $advance = $this->advance($this->employee('EMP-'.$i), 100_000, 10_000);
            $this->recover($advance, 10_000);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $payload = $this->report();

        $this->assertCount(10, $payload['rows']);
        $this->assertLessThanOrEqual(
            8,
            $queries,
            "the report ran {$queries} queries for ten advances, which is per-advance rather than aggregate",
        );
    }

    // ──────────────────────────────────────────────── the comparison ──

    /**
     * With the disbursement booked, the register and the account agree.
     *
     * The state a company that books its advances is actually in, and the only one in which the two figures
     * should match: 100,000 debited when it was lent, 25,000 credited back by recoveries, 75,000 left in
     * both places.
     */
    public function test_a_booked_advance_agrees_with_the_account(): void
    {
        $advance = $this->advance($this->employee('EMP-1'), 100_000, 25_000);
        $this->bookDisbursement(100_000);
        $this->recover($advance, 25_000);

        // The recovery credits the account, as a payslip's deduction would.
        $entry = app(JournalEntryService::class)->create(
            ['entry_date' => '2026-07-31', 'entry_type' => 'general', 'memo' => 'Advance recovered'],
            [
                ['account_id' => Account::where('code', '1200')->firstOrFail()->id, 'credit_amount' => 25_000],
                ['account_id' => Account::where('code', '1100')->firstOrFail()->id, 'debit_amount' => 25_000],
            ],
        );
        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        app(JournalEntryService::class)->post($entry->fresh());

        $payload = $this->report();

        $this->assertSame(75_000.0, $payload['tiles'][0]['value']);
        $this->assertSame(75_000.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString('THE REGISTER AGREES WITH THE ADVANCES ACCOUNT', $payload['note']);
    }

    /**
     * An unbooked advance is explained, and named as the commonest case rather than as a fault.
     *
     * Nothing posts an advance when it is entered, so this is what most companies will see. The report has
     * to say *why* the account holds less, because the alternative reading — that somebody has lost track
     * of a receivable — is alarming and wrong.
     */
    public function test_an_unbooked_advance_is_explained_as_such(): void
    {
        $this->advance($this->employee('EMP-1'), 100_000, 25_000);

        $payload = $this->report();

        $this->assertSame(100_000.0, $payload['tiles'][0]['value']);
        $this->assertSame(0.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString('THE ACCOUNT HOLDS 100,000 LESS THAN THE REGISTER', $payload['note']);
        $this->assertStringContainsString('NOTHING POSTS AN ADVANCE WHEN IT IS ENTERED', $payload['note']);
    }

    /** And the other direction is a different sentence, because it means something else entirely. */
    public function test_an_account_holding_more_reads_differently(): void
    {
        $advance = $this->advance($this->employee('EMP-1'), 100_000, 25_000);
        $this->bookDisbursement(100_000);
        // Recovered in the register and never credited in the ledger.
        $this->recover($advance, 40_000);

        $payload = $this->report();

        $this->assertSame(60_000.0, $payload['tiles'][0]['value']);
        $this->assertSame(100_000.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString('THE ACCOUNT HOLDS 40,000 MORE THAN THE REGISTER', $payload['note']);
        $this->assertStringNotContainsString('NOTHING POSTS AN ADVANCE', $payload['note']);
    }

    /**
     * Without accounting there is no comparison, and the register still reads.
     *
     * `advances` requires `payroll` and deliberately not `accounting`, so this is a licensable state and the
     * report has to degrade into it rather than disappear — the guard that earns the `advances -> accounting`
     * coupling in `ModuleBoundaryTest`.
     */
    public function test_without_accounting_the_register_still_reads(): void
    {
        $this->advance($this->employee('EMP-1'), 100_000, 25_000);

        // Payroll requires accounting, so switching accounting off takes payroll with it — and advances
        // requires payroll. Asserted through the page's own gate below; the renderer is what degrades.
        $this->setModule('accounting', false);

        $payload = $this->report();

        $this->assertCount(1, $payload['rows'], 'the register is the report and must survive');
        $this->assertSame(100_000.0, $payload['tiles'][0]['value']);
        $this->assertSame(0.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString('NO ADVANCES ACCOUNT IS AVAILABLE', $payload['note']);
    }

    // ──────────────────────────────────────────────────── the register ──

    /** Months left is the outstanding over the instalment, rounded up. */
    public function test_months_left_rounds_up(): void
    {
        // 45,000 left at 20,000 a month is three months, not two and a quarter.
        $advance = $this->advance($this->employee('EMP-1'), 100_000, 20_000);
        $this->recover($advance, 55_000);

        $this->assertSame('3', $this->row($this->report(), 'EMP-1')[5]);
    }

    /**
     * An advance with no instalment says so in both columns.
     *
     * Nothing will ever deduct it, which is a different problem from one that deducts nought — and a number
     * of months for a repayment nothing takes would be a fabrication.
     */
    public function test_an_advance_with_no_instalment_is_named(): void
    {
        $this->advance($this->employee('EMP-1'), 100_000, 0);

        $row = $this->row($this->report(), 'EMP-1');

        $this->assertSame('—', $row[4]);
        $this->assertSame('—', $row[5]);
        $this->assertStringContainsString('1 HAS NO INSTALMENT, SO NOTHING DEDUCTS THEM', $this->report()['note']);
    }

    /** A settled advance is off the register. */
    public function test_a_settled_advance_is_not_listed(): void
    {
        $advance = $this->advance($this->employee('EMP-1'), 50_000, 50_000);
        $this->recover($advance, 50_000);
        $advance->settleIfCleared();

        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NOBODY HAS AN ADVANCE OUTSTANDING', $payload['note']);
    }

    /** An advance starting after the date has not been lent yet. */
    public function test_an_advance_starting_later_is_not_yet_lent(): void
    {
        $this->advance($this->employee('EMP-1'), 50_000, 10_000, ['started_on' => '2026-09-01']);

        $this->assertSame([], $this->report()['rows']);
    }

    /** Two advances for one person are two rows, because the instalments differ. */
    public function test_two_advances_for_one_person_are_two_rows(): void
    {
        $employee = $this->employee('EMP-1');
        $this->advance($employee, 100_000, 25_000, ['reference' => 'ADV-1']);
        $this->advance($employee, 40_000, 5_000, ['reference' => 'ADV-2']);

        $payload = $this->report();

        $this->assertCount(2, $payload['rows']);
        $this->assertStringContainsString('ADV-1', collect($payload['rows'])->flatten()->implode(' '));
        $this->assertStringContainsString('ADV-2', collect($payload['rows'])->flatten()->implode(' '));
    }

    /** The record row foots the rows above it. */
    public function test_the_record_row_foots_the_rows(): void
    {
        $one = $this->advance($this->employee('EMP-1'), 100_000, 25_000);
        $this->recover($one, 25_000);
        $this->advance($this->employee('EMP-2'), 40_000, 5_000);

        $payload = $this->report();

        foreach ([1, 2, 3] as $column) {
            $rows = array_sum(array_map(
                fn (array $row): float => (float) str_replace([',', '—'], ['', '0'], $row[$column]),
                $payload['rows'],
            ));

            $this->assertSame(
                (float) str_replace(',', '', $payload['footer'][$column]),
                $rows,
                "column {$column} does not add up the rows above it",
            );
        }
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->advance($this->employee('EMP-1'), 100_000, 25_000);

        $onThePage = Livewire::test(AdvancesOutstanding::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('AdvancesOutstanding', self::AS_OF, false, []));
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertTrue(AdvancesOutstanding::canAccess(), 'an administrator should be able to open it');

        $this->actingAs(\App\Modules\Core\Models\User::factory()->create(['status' => 1]));

        $this->assertFalse(AdvancesOutstanding::canAccess());
    }
}
