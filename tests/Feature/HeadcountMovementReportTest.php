<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Pages\HeadcountMovement;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeJobHistory;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Headcount movement and turnover — `docs/reports-expansion-plan.md` Phase 3.6.
 *
 * Three arithmetic decisions carry this report, and each is wrong in a different direction if taken the
 * obvious way:
 *
 *  - **Turnover is over the *average* headcount.** Against opening, a company that halved understates its
 *    rate; against closing, it overstates it — or divides by nought in a month that ended empty.
 *  - **Tenure is continuous service, from the first job-history row.** From the original joining date it would
 *    credit the company for a break in somebody's employment.
 *  - **Joiners are counted from the joining date**, not from job history, because somebody re-employed has
 *    joined again and a month's joiners is a fact about that month.
 *
 * The last two pull in opposite directions on the same person, which is why both have a test.
 */
class HeadcountMovementReportTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    /** End of the 2026-2027 fiscal year's first half, so the period spans several months. */
    private const AS_OF = '2026-12-31';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'employees'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function employee(string $code, string $joined, ?string $left = null): Employee
    {
        return Employee::create([
            'employee_id' => $code,
            'name' => $code,
            'gender' => 'Male',
            'is_active' => $left === null,
            'date_of_joining' => $joined,
            'left_on' => $left,
        ]);
    }

    private function jobHistory(Employee $employee, string $effectiveFrom): EmployeeJobHistory
    {
        return EmployeeJobHistory::create([
            'employee_id' => $employee->id,
            'effective_from' => $effectiveFrom,
            'designation' => 'Engineer',
        ]);
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('HeadcountMovement', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for HeadcountMovement');

        return $payload;
    }

    private function cell(array $payload, string $month, string $column): string
    {
        $index = array_search($column, $payload['columns'], true);
        $this->assertNotFalse($index, "no [{$column}] column");

        foreach ($payload['rows'] as $row) {
            if (str_contains($row[0], $month)) {
                return $row[$index];
            }
        }

        $this->fail("no row for [{$month}] in ".collect($payload['rows'])->pluck(0)->implode(' | '));
    }

    // ─────────────────────────────────────────────── joiners and leavers ──

    /** Somebody joining lands in their own month, and the headcount follows. */
    public function test_a_joiner_lands_in_the_month_they_joined(): void
    {
        $this->employee('EMP-1', '2026-08-15');

        $payload = $this->report();

        $this->assertSame('1', $this->cell($payload, 'August 2026', 'Joiners'));
        $this->assertSame('—', $this->cell($payload, 'July 2026', 'Joiners'));
        $this->assertSame('0', $this->cell($payload, 'July 2026', 'Headcount'));
        $this->assertSame('1', $this->cell($payload, 'August 2026', 'Headcount'));
    }

    /** And somebody leaving drops out of the headcount from the following month. */
    public function test_a_leaver_drops_out_of_the_headcount(): void
    {
        $this->employee('EMP-1', '2026-07-01', '2026-09-30');

        $payload = $this->report();

        $this->assertSame('1', $this->cell($payload, 'September 2026', 'Leavers'));
        $this->assertSame('1', $this->cell($payload, 'September 2026', 'Headcount'), 'still there on the last day');
        $this->assertSame('0', $this->cell($payload, 'October 2026', 'Headcount'));
    }

    /**
     * Somebody who joins and leaves in one month appears in both columns.
     *
     * Which is what keeps the joiner and leaver columns consistent with the headcount between them: the
     * alternative is a month where one person arrived, one left, and the report shows neither.
     */
    public function test_somebody_joining_and_leaving_in_one_month_appears_in_both_columns(): void
    {
        $this->employee('EMP-1', '2026-08-05', '2026-08-25');

        $payload = $this->report();

        $this->assertSame('1', $this->cell($payload, 'August 2026', 'Joiners'));
        $this->assertSame('1', $this->cell($payload, 'August 2026', 'Leavers'));
    }

    /** A month where nothing happened reads as dashes, so the months that moved stand out. */
    public function test_a_quiet_month_reads_as_dashes(): void
    {
        $this->employee('EMP-1', '2026-07-01');

        $payload = $this->report();

        $this->assertSame('—', $this->cell($payload, 'September 2026', 'Joiners'));
        $this->assertSame('—', $this->cell($payload, 'September 2026', 'Leavers'));
        $this->assertSame('1', $this->cell($payload, 'September 2026', 'Headcount'));
    }

    // ──────────────────────────────────────────────────────── turnover ──

    /**
     * Turnover is over the average of opening and closing headcount.
     *
     * Ten people at the start of the month, two leave: the average is nine, so the rate is 22.2%. Against
     * opening it would read 20% and against closing 25% — and the middle one is the convention because it is
     * the only one that behaves when a company shrinks sharply.
     */
    public function test_turnover_is_measured_against_the_average_headcount(): void
    {
        foreach (range(1, 10) as $i) {
            $this->employee('EMP-'.$i, '2026-07-01', $i <= 2 ? '2026-09-15' : null);
        }

        // Opening 10, closing 8, average 9. Two of nine is 22.2%.
        $this->assertSame('22.2%', $this->cell($this->report(), 'September 2026', 'Turnover'));
    }

    /**
     * Turnover above 100% is a real answer, not an overflow.
     *
     * Somebody joins and leaves inside one month: opening headcount nought, closing one, so the average is
     * a half and one leaver of half a person is 200%. That reads oddly and it is correct — churn genuinely can
     * exceed average headcount, and a formula that capped it at 100% would hide the months worth looking at.
     * It is also the case that divides by zero if the denominator is the closing headcount alone, which is
     * the reason the average is used.
     */
    public function test_turnover_above_one_hundred_per_cent_is_a_real_answer(): void
    {
        // Joined in August so July is genuinely empty, and left on the last day of August.
        $this->employee('EMP-1', '2026-08-01', '2026-08-31');

        $payload = $this->report();

        $this->assertSame('200.0%', $this->cell($payload, 'August 2026', 'Turnover'));
        $this->assertSame('0', $this->cell($payload, 'September 2026', 'Headcount'));

        /*
         * September reads 0.0%, not a dash, and that is right.
         *
         * Its opening headcount is August's closing — one person, employed on the 31st because they left
         * that day — so the average is 0.5 and nobody left in September. Nought per cent turnover is the
         * true statement. A dash means the average headcount was nought, which is a company with nobody in
         * it at all, and July below is that case.
         */
        $this->assertSame('0.0%', $this->cell($payload, 'September 2026', 'Turnover'));
        $this->assertSame('—', $this->cell($payload, 'July 2026', 'Turnover'), 'nobody employed at all');
    }

    /** Nobody employed at all is a dash, not nought per cent. */
    public function test_a_month_with_nobody_has_no_rate(): void
    {
        $this->employee('EMP-1', '2026-10-01');

        $this->assertSame('—', $this->cell($this->report(), 'July 2026', 'Turnover'));
    }

    // ─────────────────────────────────────────────────────────── tenure ──

    /**
     * Tenure runs from the first job-history row, not the joining date.
     *
     * Somebody who joined in 2020, left, and was re-employed in 2026 has one year of continuous service and
     * not six. Measuring from the joining date would credit the company for the years they were not there.
     */
    public function test_tenure_is_continuous_service_from_the_first_job_history_row(): void
    {
        $employee = $this->employee('EMP-1', '2020-01-01', '2026-09-30');
        // Re-employed: the current span begins here.
        $this->jobHistory($employee, '2025-10-01');

        // October 2025 to September 2026 is about one year, not the six from 2020.
        $this->assertSame('1.0', $this->cell($this->report(), 'September 2026', 'Avg tenure'));
    }

    /** Where there is no job history, the joining date is the fallback. */
    public function test_tenure_falls_back_to_the_joining_date(): void
    {
        $this->employee('EMP-1', '2024-09-30', '2026-09-30');

        $this->assertSame('2.0', $this->cell($this->report(), 'September 2026', 'Avg tenure'));
    }

    /**
     * The *earliest* job-history row is the one that counts, not the latest.
     *
     * Two rows — the re-employment and a later promotion. Measuring from the promotion would report a tenure
     * of months for somebody who had been back for a year, which is the failure mode a `keyBy` in the wrong
     * order produces silently.
     */
    public function test_the_earliest_job_history_row_is_used_not_the_latest(): void
    {
        $employee = $this->employee('EMP-1', '2020-01-01', '2026-09-30');
        $this->jobHistory($employee, '2025-10-01');
        $this->jobHistory($employee, '2026-06-01');

        $this->assertSame('1.0', $this->cell($this->report(), 'September 2026', 'Avg tenure'));
    }

    /** A month with no leavers has no average tenure. */
    public function test_a_month_with_no_leavers_has_no_average_tenure(): void
    {
        $this->employee('EMP-1', '2026-07-01');

        $this->assertSame('—', $this->cell($this->report(), 'August 2026', 'Avg tenure'));
    }

    // ──────────────────────────────────────────────────── the whole period ──

    /** The note gives the net change, which is what somebody opens the report to see. */
    public function test_the_note_states_the_net_change(): void
    {
        $this->employee('EMP-1', '2026-07-01');
        $this->employee('EMP-2', '2026-08-01');
        $this->employee('EMP-3', '2026-08-01', '2026-09-30');

        $payload = $this->report();

        $this->assertStringContainsString('0 TO 2 (+2)', $payload['note']);
        $this->assertStringContainsString('3 JOINED, 1 LEFT', $payload['note']);
    }

    /** The period is the financial year to date. */
    public function test_the_period_is_the_financial_year(): void
    {
        $this->employee('EMP-1', '2026-07-15');

        $payload = $this->report();

        $this->assertStringContainsString('2026-07-01', $payload['subtitle']);
        // July to December inclusive.
        $this->assertCount(6, $payload['rows']);
    }

    /** Nobody at all is a sentence, not an empty grid. */
    public function test_it_says_when_nobody_is_on_the_payroll(): void
    {
        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NOBODY IS ON THE PAYROLL', $payload['note']);
    }

    /** Two queries whatever the headcount, because a month is a filter and not a query. */
    public function test_it_does_not_query_per_month_or_per_employee(): void
    {
        foreach (range(1, 20) as $i) {
            $this->employee('EMP-'.$i, '2026-07-01', $i <= 3 ? '2026-09-30' : null);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $payload = $this->report();

        $this->assertCount(6, $payload['rows']);
        $this->assertLessThanOrEqual(
            5,
            $queries,
            "the report ran {$queries} queries for twenty employees over six months, which is per-month or "
            .'per-employee rather than aggregate',
        );
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->employee('EMP-1', '2026-07-01');

        $onThePage = Livewire::test(HeadcountMovement::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('HeadcountMovement', self::AS_OF, false, []));
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertFalse(HeadcountMovement::canAccess());
    }
}
