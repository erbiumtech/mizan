<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\AttendanceRegularization;
use App\Modules\Attendance\Models\WorkPattern;
use App\Modules\Attendance\Services\AttendanceCalendar;
use App\Modules\Attendance\Services\AttendanceImport;
use App\Modules\Attendance\Services\AttendanceRecorder;
use App\Modules\Attendance\Services\CompensatoryOffAccrual;
use App\Modules\Attendance\Services\RegularizationService;
use App\Modules\Attendance\Services\WorkPatternResolver;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\Holiday;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Attendance and compensatory off — docs/hrms-plan.md §4.2, §4.1 and the §10 cases for
 * phases 2 and 2a.
 *
 * The through-line: **`not_marked` is not `absent`**. Almost every test below is
 * ultimately about keeping those two apart, because the moment they blur, a month
 * nobody filled in starts costing people money.
 */
class AttendanceTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $actor;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->actor = User::factory()->create(['status' => 1]);
        $this->actingAs($this->actor);
        $this->setCurrentTenant();

        $this->employee = $this->makeEmployee('EMP-1');

        $this->setModule('attendance', true);
        $this->setModule('employees', true);
    }

    private function makeEmployee(string $employeeId, array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'employee_id' => $employeeId,
            'name' => $employeeId,
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ], $attributes));
    }

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function set(string $key, mixed $value): void
    {
        app(TenantSettings::class)->set($key, $value);
    }

    /** Mon–Fri, eight hours, which is what most of these tests assume. */
    private function makeFiveDayPattern(): WorkPattern
    {
        $pattern = WorkPattern::create(['name' => 'Office, Mon-Fri', 'is_default' => true]);

        foreach (range(1, 7) as $weekday) {
            $pattern->days()->create([
                'weekday' => $weekday,
                'is_working' => $weekday <= 5,
                'expected_hours' => $weekday <= 5 ? 8 : null,
                'start_time' => $weekday <= 5 ? '09:00' : null,
            ]);
        }

        app(WorkPatternResolver::class)->flush();

        return $pattern->fresh('days');
    }

    // ---------------------------------------------------------------- work patterns

    public function test_a_pattern_answers_which_days_are_worked_and_how_long(): void
    {
        $this->makeFiveDayPattern();
        $resolver = app(WorkPatternResolver::class);

        // 2026-08-14 Friday, 2026-08-15 Saturday.
        $this->assertTrue($resolver->isWorkingDay($this->employee, '2026-08-14'));
        $this->assertFalse($resolver->isWorkingDay($this->employee, '2026-08-15'));
        $this->assertSame(8.0, $resolver->expectedHours($this->employee, '2026-08-14'));
        $this->assertNull($resolver->expectedHours($this->employee, '2026-08-15'));
    }

    /** Only one default, or which pattern a new employee falls under is insertion order. */
    public function test_setting_a_new_default_clears_the_old_one(): void
    {
        $first = WorkPattern::create(['name' => 'Office', 'is_default' => true]);
        $second = WorkPattern::create(['name' => 'Factory', 'is_default' => true]);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    /**
     * A six-day company gets six-day answers.
     *
     * This is what a single company-wide weekend setting could never express, and the
     * reason patterns are rows.
     */
    public function test_a_six_day_pattern_makes_saturday_a_working_day(): void
    {
        $pattern = WorkPattern::create(['name' => 'Factory, six days', 'is_default' => true]);

        foreach (range(1, 7) as $weekday) {
            $pattern->days()->create([
                'weekday' => $weekday,
                'is_working' => $weekday <= 6,
                'expected_hours' => $weekday <= 6 ? 8 : null,
            ]);
        }

        app(WorkPatternResolver::class)->flush();

        $this->assertTrue(app(WorkPatternResolver::class)->isWorkingDay($this->employee, '2026-08-15'));
    }

    // ---------------------------------------------------------------- recording

    public function test_recording_a_day_derives_worked_minutes_and_overtime(): void
    {
        $this->makeFiveDayPattern();

        $day = app(AttendanceRecorder::class)->record(
            $this->employee, '2026-08-14', AttendanceDay::STATUS_PRESENT, '09:00', '19:00',
        );

        $this->assertSame(600, $day->worked_minutes);
        // Ten hours against an eight-hour day.
        $this->assertSame(120, $day->overtime_minutes);
    }

    /** A shift ending after midnight is a long day, not a negative one. */
    public function test_a_night_shift_crossing_midnight_is_not_negative(): void
    {
        $this->makeFiveDayPattern();

        $day = app(AttendanceRecorder::class)->record(
            $this->employee, '2026-08-14', AttendanceDay::STATUS_PRESENT, '20:00', '04:00',
        );

        $this->assertSame(480, $day->worked_minutes);
    }

    /**
     * On a day off, every worked minute is overtime — there were no expected hours to
     * exceed.
     */
    public function test_every_minute_of_a_worked_day_off_is_overtime(): void
    {
        $this->makeFiveDayPattern();

        $day = app(AttendanceRecorder::class)->record(
            $this->employee, '2026-08-15', AttendanceDay::STATUS_WEEKLY_OFF, '10:00', '14:00',
        );

        $this->assertSame(240, $day->worked_minutes);
        $this->assertSame(240, $day->overtime_minutes);
    }

    /** Lateness is measured past a grace period, and recorded rather than charged for. */
    public function test_lateness_is_measured_past_the_grace_period(): void
    {
        $this->makeFiveDayPattern();
        $this->set('attendance.late_grace_minutes', 15);

        $onTime = app(AttendanceRecorder::class)->record(
            $this->employee, '2026-08-14', AttendanceDay::STATUS_PRESENT, '09:10', '17:00',
        );
        $this->assertSame(0, $onTime->late_minutes);

        $late = app(AttendanceRecorder::class)->record(
            $this->employee, '2026-08-13', AttendanceDay::STATUS_PRESENT, '09:45', '17:00',
        );
        $this->assertSame(30, $late->late_minutes, 'Measured from the end of the grace period, not from 09:00.');
    }

    /** Idempotent on (employee, date), which is what makes a re-import safe. */
    public function test_recording_the_same_day_twice_updates_rather_than_duplicates(): void
    {
        $this->makeFiveDayPattern();

        app(AttendanceRecorder::class)->record($this->employee, '2026-08-14', AttendanceDay::STATUS_ABSENT);
        app(AttendanceRecorder::class)->record($this->employee, '2026-08-14', AttendanceDay::STATUS_PRESENT, '09:00', '17:00');

        $this->assertSame(1, AttendanceDay::where('employee_id', $this->employee->id)->count());
        $this->assertSame(AttendanceDay::STATUS_PRESENT, AttendanceDay::first()->status);
    }

    // ---------------------------------------------------------------- the leave contradiction

    /**
     * §10.12 — an import cannot mark `present` a day covered by approved leave.
     *
     * The single most important interaction between these two modules. Letting the
     * import win would make the leave register wrong without touching it.
     */
    public function test_a_day_covered_by_approved_leave_cannot_be_marked_present(): void
    {
        $this->setModule('leave', true);
        $this->makeFiveDayPattern();
        $this->approveLeaveOn('2026-08-14');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('covered by approved leave');

        app(AttendanceRecorder::class)->record(
            $this->employee, '2026-08-14', AttendanceDay::STATUS_PRESENT, '09:00', '17:00',
        );
    }

    /** And the importer refuses it by line rather than aborting the whole file. */
    public function test_the_importer_reports_the_leave_clash_and_keeps_the_other_rows(): void
    {
        $this->setModule('leave', true);
        $this->makeFiveDayPattern();
        $this->approveLeaveOn('2026-08-14');

        $csv = "employee_id,date,status,check_in,check_out,note\n"
            ."EMP-1,2026-08-14,P,09:00,17:00,\n"
            ."EMP-1,2026-08-13,P,09:00,17:00,\n";

        $result = app(AttendanceImport::class)->import($csv);

        $this->assertSame(1, $result->accepted, 'The clean row still imports.');
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('covered by approved leave', $result->errors[0]);
    }

    /** The dry run runs every check and writes nothing — otherwise it is not a dry run. */
    public function test_the_import_preview_writes_nothing(): void
    {
        $this->makeFiveDayPattern();

        $csv = "employee_id,date,status\nEMP-1,2026-08-14,P\nEMP-1,2026-08-13,A\n";

        $result = app(AttendanceImport::class)->preview($csv);

        $this->assertSame(2, $result->accepted);
        $this->assertFalse($result->committed);
        $this->assertSame(0, AttendanceDay::count(), 'A preview must not write.');
    }

    public function test_the_importer_names_an_unknown_employee_rather_than_counting_it(): void
    {
        $this->makeFiveDayPattern();

        $result = app(AttendanceImport::class)->import(
            "employee_id,date,status\nNOBODY,2026-08-14,P\n"
        );

        $this->assertSame(0, $result->accepted);
        $this->assertStringContainsString('NOBODY', $result->errors[0]);
    }

    /** Whatever a device or a clerk writes for "present" means present. */
    public function test_the_importer_accepts_the_short_status_codes(): void
    {
        $this->makeFiveDayPattern();

        app(AttendanceImport::class)->import(
            "employee_id,date,status\nEMP-1,2026-08-14,P\nEMP-1,2026-08-13,Present\nEMP-1,2026-08-12,wfh\n"
        );

        $this->assertSame(AttendanceDay::STATUS_PRESENT, AttendanceDay::forDate($this->employee->id, '2026-08-14')->status);
        $this->assertSame(AttendanceDay::STATUS_PRESENT, AttendanceDay::forDate($this->employee->id, '2026-08-13')->status);
        $this->assertSame(AttendanceDay::STATUS_WORK_FROM_HOME, AttendanceDay::forDate($this->employee->id, '2026-08-12')->status);
    }

    // ---------------------------------------------------------------- the month

    /**
     * §10.13 — a month with no rows reads `not_marked`, never `absent`.
     *
     * And the figures follow: nothing is an absence, and the unknown count is what says
     * the month cannot be trusted to reduce anybody's pay.
     */
    public function test_a_month_nobody_filled_in_is_unknown_and_not_absent(): void
    {
        $this->makeFiveDayPattern();

        $month = app(AttendanceCalendar::class)->summarise($this->employee, 2026, 8);

        // August 2026 has 21 weekdays.
        $this->assertSame(21, $month->expectedDays);
        $this->assertSame(21, $month->unknownDays);
        $this->assertSame(0.0, $month->absentDays, 'An unfilled month is not a month of absences.');
        $this->assertSame(0.0, $month->lossOfPayDays());
        $this->assertFalse($month->isComplete());
        $this->assertSame(21.0, $month->paidDays(), 'Unknown days are paid: nobody proved they were missed.');
    }

    public function test_scaffolding_a_month_marks_weekends_and_holidays_but_leaves_working_days_unknown(): void
    {
        $this->makeFiveDayPattern();
        Holiday::create(['date' => '2026-08-14', 'name' => 'Independence Day']);

        $created = app(AttendanceCalendar::class)->scaffold($this->employee, 2026, 8);

        $this->assertSame(31, $created);
        $this->assertSame(AttendanceDay::STATUS_HOLIDAY, AttendanceDay::forDate($this->employee->id, '2026-08-14')->status);
        $this->assertSame(AttendanceDay::STATUS_WEEKLY_OFF, AttendanceDay::forDate($this->employee->id, '2026-08-15')->status);
        $this->assertSame(AttendanceDay::STATUS_NOT_MARKED, AttendanceDay::forDate($this->employee->id, '2026-08-13')->status);
    }

    /** Scaffolding twice must not double a month, or overwrite what somebody recorded. */
    public function test_scaffolding_is_idempotent_and_never_overwrites(): void
    {
        $this->makeFiveDayPattern();
        app(AttendanceRecorder::class)->record($this->employee, '2026-08-13', AttendanceDay::STATUS_PRESENT, '09:00', '17:00');

        app(AttendanceCalendar::class)->scaffold($this->employee, 2026, 8);
        app(AttendanceCalendar::class)->scaffold($this->employee, 2026, 8);

        $this->assertSame(31, AttendanceDay::where('employee_id', $this->employee->id)->count());
        $this->assertSame(AttendanceDay::STATUS_PRESENT, AttendanceDay::forDate($this->employee->id, '2026-08-13')->status);
    }

    public function test_a_complete_month_reports_its_absences_and_is_trustworthy(): void
    {
        $this->makeFiveDayPattern();
        app(AttendanceCalendar::class)->scaffold($this->employee, 2026, 8);

        AttendanceDay::where('employee_id', $this->employee->id)
            ->where('status', AttendanceDay::STATUS_NOT_MARKED)
            ->update(['status' => AttendanceDay::STATUS_PRESENT]);

        app(AttendanceRecorder::class)->record($this->employee, '2026-08-13', AttendanceDay::STATUS_ABSENT);

        $month = app(AttendanceCalendar::class)->summarise($this->employee, 2026, 8);

        $this->assertTrue($month->isComplete());
        $this->assertSame(1.0, $month->absentDays);
        $this->assertSame(20.0, $month->paidDays());
        $this->assertNull($month->completenessNote());
    }

    /** A holiday is expected of nobody, so it is not part of the month's denominator. */
    public function test_a_holiday_is_not_an_expected_day(): void
    {
        $this->makeFiveDayPattern();
        Holiday::create(['date' => '2026-08-14', 'name' => 'Independence Day']);

        $this->assertSame(20, app(AttendanceCalendar::class)->summarise($this->employee, 2026, 8)->expectedDays);
    }

    // ---------------------------------------------------------------- regularization

    public function test_an_approved_correction_writes_the_day_and_keeps_the_request(): void
    {
        $this->makeFiveDayPattern();
        $approver = User::factory()->create(['status' => 1]);

        $request = app(RegularizationService::class)->submit(
            new AttendanceRegularization([
                'employee_id' => $this->employee->id,
                'date' => '2026-08-13',
                'requested_status' => AttendanceDay::STATUS_PRESENT,
                'requested_check_in_at' => '2026-08-13 09:00',
                'requested_check_out_at' => '2026-08-13 17:00',
                'reason' => 'The reader was down that morning.',
            ]),
            $this->actor,
        );

        $day = app(RegularizationService::class)->approve($request, $approver);

        $this->assertSame(AttendanceDay::STATUS_PRESENT, $day->status);
        $this->assertSame(AttendanceDay::SOURCE_SELF_SERVICE, $day->source);
        $this->assertSame(480, $day->worked_minutes);

        // The original ask survives, which is what makes this auditable rather than an
        // overwrite.
        $request->refresh();
        $this->assertSame(AttendanceRegularization::STATUS_APPROVED, $request->status);
        $this->assertSame('The reader was down that morning.', $request->reason);
        $this->assertSame($approver->id, $request->decided_by);
    }

    /** §10.13d, first half — a day covered by approved leave cannot be regularized. */
    public function test_a_correction_is_refused_for_a_day_covered_by_leave(): void
    {
        $this->setModule('leave', true);
        $this->makeFiveDayPattern();
        $this->approveLeaveOn('2026-08-14');

        // Scaffolding writes the on_leave day with its leave_request_id.
        app(AttendanceCalendar::class)->scaffold($this->employee, 2026, 8);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('covered by approved leave');

        app(RegularizationService::class)->submit(
            new AttendanceRegularization([
                'employee_id' => $this->employee->id,
                'date' => '2026-08-14',
                'requested_status' => AttendanceDay::STATUS_PRESENT,
                'reason' => 'I actually came in.',
            ]),
            $this->actor,
        );
    }

    /** Nobody decides a correction about themselves, and there is no setting to waive it. */
    public function test_a_correction_cannot_be_approved_by_the_person_it_is_about(): void
    {
        $this->makeFiveDayPattern();
        $self = $this->makeEmployee('EMP-SELF', ['user_id' => $this->actor->id]);

        $request = app(RegularizationService::class)->submit(
            new AttendanceRegularization([
                'employee_id' => $self->id,
                'date' => '2026-08-13',
                'requested_status' => AttendanceDay::STATUS_PRESENT,
                'reason' => 'Forgot to badge in.',
            ]),
            $this->actor,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be approved by the person it is about');

        app(RegularizationService::class)->approve($request, $this->actor);
    }

    /** Asking for "not marked" is asking for nothing. */
    public function test_a_correction_must_say_what_the_day_was(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('asking for nothing');

        app(RegularizationService::class)->submit(
            new AttendanceRegularization([
                'employee_id' => $this->employee->id,
                'date' => '2026-08-13',
                'requested_status' => AttendanceDay::STATUS_NOT_MARKED,
                'reason' => 'Unsure.',
            ]),
            $this->actor,
        );
    }

    // ---------------------------------------------------------------- compensatory off

    /**
     * §10.13c — a worked day off credits a day in lieu.
     *
     * From the attendance record, which employees cannot write: they hold View and the
     * right to *ask* for a correction, and nothing else. So a comp-off is always backed
     * by something HR entered, imported, or approved.
     */
    public function test_a_worked_day_off_credits_a_compensatory_day(): void
    {
        $this->setModule('leave', true);
        $this->makeFiveDayPattern();
        $this->makeCompOffType();

        app(AttendanceRecorder::class)->record(
            $this->employee, now()->subDays(10)->toDateString(), AttendanceDay::STATUS_WEEKLY_OFF, '10:00', '15:00',
        );

        app(CompensatoryOffAccrual::class)->accrue();

        $this->assertSame(1.0, (float) LeaveEntitlement::first()->accrued_days);
    }

    /** A day off that was NOT worked credits nothing. */
    public function test_an_unworked_day_off_credits_nothing(): void
    {
        $this->setModule('leave', true);
        $this->makeFiveDayPattern();
        $this->makeCompOffType();

        app(AttendanceRecorder::class)->record(
            $this->employee, now()->subDays(10)->toDateString(), AttendanceDay::STATUS_WEEKLY_OFF,
        );

        app(CompensatoryOffAccrual::class)->accrue();

        $this->assertSame(0, LeaveEntitlement::where('accrued_days', '>', 0)->count());
    }

    /**
     * §10.13c, second half — a credit older than the expiry window is not spendable.
     *
     * The one lapse date this plan family admits, and deliberately: a comp-off earned in
     * March and taken three years later is not time off *in lieu* of anything.
     */
    public function test_a_compensatory_credit_expires(): void
    {
        $this->setModule('leave', true);
        $this->makeFiveDayPattern();
        $this->makeCompOffType();
        $this->set('attendance.comp_off_expiry_days', 90);

        app(AttendanceRecorder::class)->record(
            $this->employee, now()->subDays(120)->toDateString(), AttendanceDay::STATUS_HOLIDAY, '10:00', '15:00',
        );

        app(CompensatoryOffAccrual::class)->accrue();

        $this->assertSame(
            0.0,
            (float) (LeaveEntitlement::first()?->accrued_days ?? 0),
            'A credit past its window must stop being spendable without anybody touching it.',
        );
    }

    /** Recomputed rather than incremented, so a second run in one day cannot double it. */
    public function test_accruing_twice_does_not_double_the_credit(): void
    {
        $this->setModule('leave', true);
        $this->makeFiveDayPattern();
        $this->makeCompOffType();

        app(AttendanceRecorder::class)->record(
            $this->employee, now()->subDays(5)->toDateString(), AttendanceDay::STATUS_WEEKLY_OFF, '10:00', '15:00',
        );

        app(CompensatoryOffAccrual::class)->accrue();
        app(CompensatoryOffAccrual::class)->accrue();

        $this->assertSame(1.0, (float) LeaveEntitlement::first()->accrued_days);
        $this->assertSame(1, LeaveEntitlement::count());
    }

    /** Without `leave` there is nowhere to put a credit, and that is not an error. */
    public function test_compensatory_accrual_does_nothing_without_the_leave_module(): void
    {
        $this->setModule('leave', false);
        $this->makeFiveDayPattern();

        app(AttendanceRecorder::class)->record(
            $this->employee, now()->subDays(5)->toDateString(), AttendanceDay::STATUS_WEEKLY_OFF, '10:00', '15:00',
        );

        $this->assertSame(0, app(CompensatoryOffAccrual::class)->accrue());
    }

    // ---------------------------------------------------------------- leave integration

    /**
     * With `attendance` licensed, leave counts days by the employee's own pattern.
     *
     * This is what config/leave.php promised when it called leave.weekend_days a
     * stopgap: a six-day worker's Saturday leave costs them a day.
     */
    public function test_leave_counts_saturday_for_an_employee_on_a_six_day_pattern(): void
    {
        $this->setModule('leave', true);

        $pattern = WorkPattern::create(['name' => 'Factory, six days', 'is_default' => true]);

        foreach (range(1, 7) as $weekday) {
            $pattern->days()->create([
                'weekday' => $weekday,
                'is_working' => $weekday <= 6,
                'expected_hours' => $weekday <= 6 ? 8 : null,
            ]);
        }

        app(WorkPatternResolver::class)->flush();

        // Friday to Saturday: two working days on a six-day pattern, one on a five-day.
        $request = $this->submitLeave('2026-08-14', '2026-08-15');
        app(LeaveRequestService::class)->approve($request, User::factory()->create(['status' => 1]));

        $this->assertSame(2.0, (float) $request->fresh()->days);
    }

    // ---------------------------------------------------------------- helpers

    private function makeCompOffType(): LeaveType
    {
        return LeaveType::create([
            'code' => 'comp_off',
            'label' => 'Compensatory Off',
            'accrual_method' => LeaveType::ACCRUAL_COMPENSATORY,
            'days_per_year' => 12,
        ]);
    }

    private function submitLeave(string $from, string $to): LeaveRequest
    {
        $type = LeaveType::firstOrCreate(
            ['code' => 'annual'],
            ['label' => 'Annual Leave', 'days_per_year' => 14],
        );

        return app(LeaveRequestService::class)->submit(
            new LeaveRequest([
                'employee_id' => $this->employee->id,
                'leave_type_id' => $type->id,
                'from_date' => $from,
                'to_date' => $to,
            ]),
            $this->actor,
        );
    }

    private function approveLeaveOn(string $date): LeaveRequest
    {
        $request = $this->submitLeave($date, $date);

        app(LeaveRequestService::class)->approve($request, User::factory()->create(['status' => 1]));

        return $request->fresh();
    }
}
