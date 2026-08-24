<?php

namespace Tests\Feature;

use App\Modules\Attendance\Filament\Pages\AttendanceRegister as AttendanceRegisterPage;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\WorkPatternResolver;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use App\Support\TenantDb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The monthly attendance register — `docs/reports-expansion-plan.md` Phase 3.1.
 *
 * Three things are worth asserting here, and the third is the reason the other two exist.
 *
 *  - **An unmarked day is counted as worked**, and the report says how many there were. That is
 *    long-standing behaviour — "a day nobody recorded is not a day anybody missed" — and its consequence is
 *    that an unfilled month reads as a good one unless something counts the gaps.
 *  - **A payslip that prorated on different paid days is reported.** The plan's stated value for this report
 *    is that the disagreement becomes visible, and a disagreement means pay was calculated on a figure this
 *    calendar does not reproduce.
 *  - **The query count does not grow with the days in the month.** It did before this report existed:
 *    `WorkPatternResolver` cached patterns per employee *per day*, so one month cost 31 queries a head. A
 *    register over forty people would have been upwards of twelve hundred queries, which is not a report.
 *
 * `AttendanceTest` owns the calendar arithmetic — expected days, holidays, patterns, proration — and none of
 * it is restated here.
 */
class AttendanceRegisterReportTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const AS_OF = '2026-08-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['employees', 'attendance'] as $module) {
            $this->setModule($module, true);
        }

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
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

    private function employee(string $code, array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'employee_id' => $code,
            'name' => $code,
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ], $attributes));
    }

    private function mark(Employee $employee, string $date, string $status, array $attributes = []): AttendanceDay
    {
        return AttendanceDay::create(array_merge([
            'employee_id' => $employee->id,
            'date' => $date,
            'status' => $status,
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('AttendanceRegister', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for AttendanceRegister');

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

    /** The cell for one date, found by the day-of-month column header. */
    private function cellFor(array $payload, string $code, int $day): string
    {
        $column = array_search((string) $day, $payload['columns'], true);

        $this->assertNotFalse($column, "no column for day {$day}");

        return $this->row($payload, $code)[$column];
    }

    // ──────────────────────────────────────────────────────────── the grid ──

    /** A letter per status, and every status has one. */
    public function test_each_status_has_its_own_letter(): void
    {
        $employee = $this->employee('EMP-1');

        $this->mark($employee, '2026-08-03', AttendanceDay::STATUS_PRESENT);
        $this->mark($employee, '2026-08-04', AttendanceDay::STATUS_ABSENT);
        $this->mark($employee, '2026-08-05', AttendanceDay::STATUS_ON_LEAVE);
        $this->mark($employee, '2026-08-06', AttendanceDay::STATUS_HALF_DAY);
        $this->mark($employee, '2026-08-07', AttendanceDay::STATUS_WORK_FROM_HOME);
        $this->mark($employee, '2026-08-08', AttendanceDay::STATUS_WEEKLY_OFF);
        $this->mark($employee, '2026-08-10', AttendanceDay::STATUS_HOLIDAY);

        $payload = $this->report();

        $this->assertSame('P', $this->cellFor($payload, 'EMP-1', 3));
        $this->assertSame('A', $this->cellFor($payload, 'EMP-1', 4));
        $this->assertSame('L', $this->cellFor($payload, 'EMP-1', 5));
        $this->assertSame('½', $this->cellFor($payload, 'EMP-1', 6));
        $this->assertSame('W', $this->cellFor($payload, 'EMP-1', 7));
        $this->assertSame('O', $this->cellFor($payload, 'EMP-1', 8));
        $this->assertSame('H', $this->cellFor($payload, 'EMP-1', 10));

        // And the legend is on the report, not only in the help.
        $this->assertStringContainsString('P PRESENT, A ABSENT', $payload['note']);
    }

    /**
     * An unmarked day is a dot, and the note counts them.
     *
     * Not a blank cell: an unmarked day is counted as *worked* by `paidDays()`, so it is a fact with a
     * consequence for pay. A month full of dots reads as a good month unless something says otherwise, and
     * that count is the first thing a payroll clerk should look at.
     */
    public function test_an_unmarked_day_is_shown_and_counted(): void
    {
        $employee = $this->employee('EMP-1');
        $this->mark($employee, '2026-08-03', AttendanceDay::STATUS_PRESENT);

        $payload = $this->report();

        $this->assertSame('·', $this->cellFor($payload, 'EMP-1', 4), 'an unmarked day is a dot, not a blank');
        $this->assertStringContainsString('DAYS NOT MARKED, COUNTED AS WORKED', $payload['note']);
    }

    /** A day column exists for every day of the month, and the month is named. */
    public function test_the_grid_covers_the_whole_month(): void
    {
        $this->employee('EMP-1');

        $payload = $this->report();

        $this->assertStringContainsString('August 2026', $payload['subtitle']);
        // Employee, 31 days, and the four totals.
        $this->assertCount(36, $payload['columns']);
        $this->assertSame('1', $payload['columns'][1]);
        $this->assertSame('31', $payload['columns'][31]);
        $this->assertTrue($payload['wide'], 'thirty-one day columns is past the width of the pane');
    }

    /** The totals are stated, and a nought reads as a dash. */
    public function test_the_totals_are_stated_and_absences_are_shown(): void
    {
        $employee = $this->employee('EMP-1');
        $this->mark($employee, '2026-08-03', AttendanceDay::STATUS_ABSENT);
        $this->mark($employee, '2026-08-04', AttendanceDay::STATUS_PRESENT, ['late_minutes' => 25, 'overtime_minutes' => 90]);

        $payload = $this->report();
        $row = $this->row($payload, 'EMP-1');

        $lop = array_search('LOP', $payload['columns'], true);
        $late = array_search('Late', $payload['columns'], true);
        $overtime = array_search('OT', $payload['columns'], true);

        $this->assertSame('1.0', $row[$lop], 'one unpaid absence');
        $this->assertSame('25', $row[$late]);
        $this->assertSame('1.5', $row[$overtime], 'ninety minutes is an hour and a half');
    }

    /** Somebody with a clean month shows dashes rather than noughts. */
    public function test_a_clean_month_reads_as_dashes(): void
    {
        $employee = $this->employee('EMP-1');
        $this->mark($employee, '2026-08-03', AttendanceDay::STATUS_PRESENT);

        $payload = $this->report();
        $row = $this->row($payload, 'EMP-1');

        $this->assertSame('—', $row[array_search('LOP', $payload['columns'], true)]);
        $this->assertSame('—', $row[array_search('Late', $payload['columns'], true)]);
        $this->assertSame('—', $row[array_search('OT', $payload['columns'], true)]);
    }

    // ─────────────────────────────────────────── the disagreement with payroll ──

    /**
     * A payslip that prorated on different paid days is reported.
     *
     * The plan's stated value for this report. Written straight into `payslips` because generating one needs
     * the payroll module and a package, and what is being tested is the *comparison* — the register reading
     * a figure payroll recorded and finding it different.
     */
    public function test_a_payslip_that_prorated_differently_is_reported(): void
    {
        $this->setModule('payroll', true);
        $this->setModule('accounting', true);

        $employee = $this->employee('EMP-1');
        $this->mark($employee, '2026-08-03', AttendanceDay::STATUS_ABSENT);

        $register = $this->report();
        $computed = (float) $this->row($register, 'EMP-1')[array_search('Paid', $register['columns'], true)];

        TenantDb::table('payslips')->insert([
            'employee_id' => $employee->id,
            'fiscal_year_id' => FiscalYear::where('name', '2026-2027')->value('id'),
            'month' => 'August',
            'paid_days' => $computed - 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertStringContainsString(
            '1 PAYSLIP PRORATED ON DIFFERENT PAID DAYS',
            $this->report()['note'],
        );
    }

    /** And one that agrees is not reported, because a column of ticks earns no space. */
    public function test_a_payslip_that_agrees_is_not_reported(): void
    {
        $this->setModule('payroll', true);
        $this->setModule('accounting', true);

        $employee = $this->employee('EMP-1');
        $this->mark($employee, '2026-08-03', AttendanceDay::STATUS_ABSENT);

        $register = $this->report();
        $computed = (float) $this->row($register, 'EMP-1')[array_search('Paid', $register['columns'], true)];

        TenantDb::table('payslips')->insert([
            'employee_id' => $employee->id,
            'fiscal_year_id' => FiscalYear::where('name', '2026-2027')->value('id'),
            'month' => 'August',
            'paid_days' => $computed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertStringNotContainsString('PRORATED ON DIFFERENT', $this->report()['note']);
    }

    /** Without payroll there are no payslips, and the report says nothing about them. */
    public function test_without_payroll_no_comparison_is_attempted(): void
    {
        $employee = $this->employee('EMP-1');
        $this->mark($employee, '2026-08-03', AttendanceDay::STATUS_ABSENT);

        $this->assertStringNotContainsString('PRORATED', $this->report()['note']);
    }

    // ───────────────────────────────────────────────────────── the cost ──

    /**
     * The query count does not grow with the days in the month.
     *
     * The reason this report is possible at all. `WorkPatternResolver::for()` cached patterns per employee
     * *per day*, so `AttendanceCalendar::summarise()` — which walks a month a day at a time — cost 31 queries
     * a head. Five employees over a 31-day month is asserted here against a ceiling that a per-day cache
     * would blow through by an order of magnitude.
     */
    public function test_it_does_not_query_per_day(): void
    {
        foreach (range(1, 5) as $i) {
            $employee = $this->employee('EMP-'.$i);
            $this->mark($employee, '2026-08-0'.$i, AttendanceDay::STATUS_PRESENT);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $payload = $this->report();

        $this->assertCount(5, $payload['rows']);
        $this->assertLessThanOrEqual(
            20,
            $queries,
            "the report ran {$queries} queries for five employees over a month, which is per-day rather than "
            .'per-employee',
        );
    }

    /**
     * And the pattern resolver answers a whole month from one query per employee.
     *
     * Asserted at the service directly, because the report's ceiling above would still pass if the resolver
     * regressed and something else got cheaper. This is the property that was actually fixed.
     */
    public function test_the_pattern_resolver_loads_an_employees_assignments_once(): void
    {
        $employee = $this->employee('EMP-1');
        $resolver = app(WorkPatternResolver::class);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        foreach (range(1, 28) as $day) {
            $resolver->isWorkingDay($employee, sprintf('2026-08-%02d', $day));
        }

        $this->assertLessThanOrEqual(
            3,
            $queries,
            "the resolver ran {$queries} queries for 28 days of one employee; it should load the "
            .'assignments once and resolve dates in memory',
        );
    }

    // ──────────────────────────────────────────────────────── who appears ──

    /** Somebody who left before the month is not on it. */
    public function test_a_leaver_from_a_previous_month_is_not_listed(): void
    {
        $this->employee('EMP-1', ['left_on' => '2026-06-30', 'is_active' => false]);
        $this->employee('EMP-2');

        $payload = $this->report();

        $this->assertCount(1, $payload['rows']);
        $this->assertStringContainsString('EMP-2', $payload['rows'][0][0]);
    }

    /** Nobody employed is a sentence, not an empty grid. */
    public function test_it_says_when_nobody_was_employed(): void
    {
        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NOBODY WAS EMPLOYED IN THIS MONTH', $payload['note']);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->mark($this->employee('EMP-1'), '2026-08-03', AttendanceDay::STATUS_PRESENT);

        $onThePage = Livewire::test(AttendanceRegisterPage::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('AttendanceRegister', self::AS_OF, false, []));
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertFalse(AttendanceRegisterPage::canAccess());
    }
}
