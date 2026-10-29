<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Support\AttendanceReports;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeJobHistory;
use App\Modules\Employees\Support\HeadcountReports;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Support\LeaveReports;
use App\Modules\Payroll\Models\AnnualTax;
use App\Modules\Payroll\Models\SalarySlab;
use App\Modules\Payroll\Support\PayrollReports;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The reports their walkthrough had and this lacked — docs/saas-launch-plan.md §14.10.
 *
 * Each one is tested on the judgement it makes rather than on its cells: the
 * anniversary report on *which* date it counts from, the absence report on keeping
 * leave out of the absence column, the slab report on what decides somebody's band.
 * Those are the decisions a reader would argue with, and the ones a refactor could
 * quietly reverse.
 */
class WalkthroughReportsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const AS_OF = '2026-08-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'walkthrough@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'attendance', 'leave', 'performance'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
    }

    private function employee(string $code, array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'employee_id' => $code,
            'name' => 'Person '.$code,
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-09-01',
        ], $attributes));
    }

    private function rows(array $report): array
    {
        return $report['rows'] ?? [];
    }

    // ------------------------------------------------------------ anniversaries

    public function test_an_anniversary_is_counted_from_continuous_service_not_the_joining_date(): void
    {
        $employee = $this->employee('EMP-1', ['date_of_joining' => '2010-09-01']);

        // Re-employed in 2022 after a break. The job history is what says so, and the
        // report must read it rather than the joining date, or it congratulates somebody
        // on sixteen years of which six were somewhere else.
        EmployeeJobHistory::create([
            'employee_id' => $employee->getKey(),
            'kind' => EmployeeJobHistory::KIND_PROMOTION,
            'effective_from' => '2022-09-01',
        ]);

        $rows = $this->rows(app(HeadcountReports::class)->anniversaries(self::AS_OF));

        $this->assertCount(1, $rows);
        $this->assertSame('01 Sep', $rows[0][3]);
        $this->assertSame('4', $rows[0][4], 'four years of continuous service, not sixteen');
    }

    public function test_a_milestone_is_flagged_and_somebody_short_of_a_year_is_not_listed(): void
    {
        $this->employee('EMP-1', ['date_of_joining' => '2021-09-10']);
        $this->employee('EMP-2', ['date_of_joining' => '2026-09-10']);

        $report = app(HeadcountReports::class)->anniversaries(self::AS_OF);
        $rows = $this->rows($report);

        $this->assertCount(1, $rows, 'the one who has not completed a year has nothing to mark');
        $this->assertSame('5', $rows[0][4]);
        $this->assertSame('Milestone', $rows[0][5]);
    }

    public function test_an_anniversary_outside_the_window_is_left_out(): void
    {
        $this->employee('EMP-1', ['date_of_joining' => '2020-02-01']);

        $this->assertSame([], $this->rows(app(HeadcountReports::class)->anniversaries(self::AS_OF)));
    }

    // ------------------------------------------------------------- demographics

    public function test_demographics_counts_what_nobody_recorded_as_its_own_row(): void
    {
        $this->employee('EMP-1', ['gender' => 'Male', 'date_of_birth' => '1990-01-01']);
        $this->employee('EMP-2', ['gender' => 'Female', 'date_of_birth' => null]);

        $rows = $this->rows(app(HeadcountReports::class)->demographics(self::AS_OF));
        $values = array_column($rows, 1);

        $this->assertContains('Not recorded', $values,
            'a demographics report whose numbers do not add up to the headcount has lost somebody');
        $this->assertContains('35–44', $values);
        $this->assertContains('Male', $values);
    }

    // --------------------------------------------------------------- attendance

    public function test_late_marks_counts_occasions_and_minutes_separately(): void
    {
        $employee = $this->employee('EMP-1');

        foreach ([['2026-08-03', 5], ['2026-08-04', 5], ['2026-08-05', 50]] as [$date, $minutes]) {
            AttendanceDay::create([
                'employee_id' => $employee->getKey(),
                'date' => $date,
                'status' => AttendanceDay::STATUS_PRESENT,
                'late_minutes' => $minutes,
            ]);
        }

        $rows = $this->rows(app(AttendanceReports::class)->lateMarks(self::AS_OF));

        $this->assertCount(1, $rows);
        $this->assertSame('3', $rows[0][2], 'three occasions');
        $this->assertSame('1h', $rows[0][3], 'sixty minutes in total');
        $this->assertSame('50m', $rows[0][4], 'the worst single mark');
        $this->assertSame('20m', $rows[0][5], 'and the average, which is the different conversation');
    }

    public function test_somebody_never_late_is_not_on_the_late_report(): void
    {
        $employee = $this->employee('EMP-1');

        AttendanceDay::create([
            'employee_id' => $employee->getKey(),
            'date' => '2026-08-03',
            'status' => AttendanceDay::STATUS_PRESENT,
            'late_minutes' => 0,
        ]);

        $this->assertSame([], $this->rows(app(AttendanceReports::class)->lateMarks(self::AS_OF)));
    }

    public function test_leave_is_never_counted_as_an_absence(): void
    {
        $employee = $this->employee('EMP-1');

        foreach ([
            ['2026-08-03', AttendanceDay::STATUS_ABSENT],
            ['2026-08-04', AttendanceDay::STATUS_ON_LEAVE],
            ['2026-08-05', AttendanceDay::STATUS_ON_LEAVE],
            ['2026-08-06', AttendanceDay::STATUS_NOT_MARKED],
        ] as [$date, $status]) {
            AttendanceDay::create(['employee_id' => $employee->getKey(), 'date' => $date, 'status' => $status]);
        }

        $rows = $this->rows(app(AttendanceReports::class)->absences(self::AS_OF));

        $this->assertCount(1, $rows);
        $this->assertSame('1', $rows[0][2], 'one absent day');
        $this->assertSame('1', $rows[0][3], 'one day nobody marked, which pays as present');
        $this->assertSame('2', $rows[0][4], 'and two of leave, beside them and not added in');
    }

    // -------------------------------------------------------------------- leave

    public function test_leave_utilisation_reads_the_balance_the_employee_sees(): void
    {
        $employee = $this->employee('EMP-1');

        $type = LeaveType::create([
            'code' => 'annual', 'label' => 'Annual', 'kind' => 'annual', 'is_paid' => true,
            'days_per_year' => 14, 'is_active' => true,
        ]);

        LeaveEntitlement::create([
            'employee_id' => $employee->getKey(),
            'leave_type_id' => $type->getKey(),
            'leave_year_start' => '2026-01-01',
            'leave_year_end' => '2026-12-31',
            'opening_days' => 14,
        ]);

        $rows = $this->rows(app(LeaveReports::class)->utilisation(self::AS_OF));

        $this->assertCount(1, $rows);
        $this->assertSame('0 / 14', $rows[1 - 1][1], 'taken over credited, as the balance screen says it');
        $this->assertSame('0%', $rows[0][count($rows[0]) - 1], 'and nothing taken is nothing used');
    }

    public function test_somebody_with_no_leave_year_open_is_not_counted_as_having_none(): void
    {
        $this->employee('EMP-1');
        LeaveType::create(['code' => 'annual', 'label' => 'Annual', 'kind' => 'annual', 'is_paid' => true, 'days_per_year' => 14, 'is_active' => true]);

        $this->assertSame(
            [],
            $this->rows(app(LeaveReports::class)->utilisation(self::AS_OF)),
            'no entitlement is a question nobody has answered, not zero days',
        );
    }

    // ---------------------------------------------------------------- tax slabs

    public function test_the_slab_is_decided_by_the_annual_projection(): void
    {
        $year = $this->fiscalYear;

        // The test case seeds the real FBR schedule. Cleared first, because what is being
        // asserted is which band a projection falls in, not the schedule's own shape.
        SalarySlab::where('fiscal_year_id', $year->id)->delete();

        SalarySlab::create(['fiscal_year_id' => $year->id, 'min_amount' => 600000, 'max_amount' => 1200000, 'fixed_tax' => 0, 'percentage' => 5]);
        SalarySlab::create(['fiscal_year_id' => $year->id, 'min_amount' => 1200000, 'max_amount' => null, 'fixed_tax' => 30000, 'percentage' => 15]);

        $below = $this->employee('EMP-1');
        $middle = $this->employee('EMP-2');
        $top = $this->employee('EMP-3');

        foreach ([[$below, 400000, 0], [$middle, 900000, 15000], [$top, 2000000, 150000]] as [$employee, $income, $tax]) {
            AnnualTax::create([
                'employee_id' => $employee->getKey(),
                'fiscal_year_id' => $year->id,
                'total_annual_income' => $income,
                'total_annual_tax' => $tax,
            ]);
        }

        $rows = $this->rows(app(PayrollReports::class)->slabAnalysis(self::AS_OF));

        $this->assertCount(3, $rows, 'two slabs and the row for everybody below the first threshold');
        $this->assertSame('1', $rows[0][3]);
        $this->assertSame('1', $rows[1][3]);
        $this->assertSame('Below the first threshold', $rows[2][0]);
        $this->assertSame('1', $rows[2][3], 'not an error, and not placed in the bottom band by default');
    }

    public function test_a_year_with_no_slabs_says_so_rather_than_reporting_nothing(): void
    {
        $this->employee('EMP-1');
        SalarySlab::where('fiscal_year_id', $this->fiscalYear->id)->delete();

        $report = app(PayrollReports::class)->slabAnalysis(self::AS_OF);

        $this->assertSame([], $this->rows($report));
        $this->assertStringContainsString('Finance Act', $report['empty'] ?? '');
    }
}
