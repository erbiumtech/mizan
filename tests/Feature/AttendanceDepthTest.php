<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Models\RestDaySwap;
use App\Modules\Attendance\Models\WorkPattern;
use App\Modules\Attendance\Services\AttendanceRecorder;
use App\Modules\Attendance\Services\OvertimeRequests;
use App\Modules\Attendance\Services\WorkPatternResolver;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\Holiday;
use App\Modules\Core\Services\CsvImportService;
use App\Modules\Core\Services\HolidayCalendar;
use App\Modules\Employees\Models\Employee;
use App\Notifications\RecordChanged;
use App\Support\TenantSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Overtime requests, a break and a grace per pattern, swaps, reminders, holidays — docs/saas-launch-plan.md §14.4.
 * One rule: approved overtime reaches the payslip, or the request says why it did not.
 */
class AttendanceDepthTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        Notification::fake();
        config(['multitenancy.switch_tenant_tasks' => [\App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class]]);
        $this->actingAs($this->makeUser('Administrator', 'att-admin@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'attendance', 'payroll', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();

        $this->employee = Employee::create(['user_id' => $this->makeUser('Employee', 'att-emp@test.local')->id, 'employee_id' => 'EMP-A', 'name' => 'Ayesha', 'gender' => 'Female', 'is_active' => 1]);
    }

    private function pattern(int $break = 0, ?int $grace = null): WorkPattern
    {
        $pattern = WorkPattern::create(['name' => 'Office', 'is_default' => true, 'break_minutes' => $break, 'grace_minutes' => $grace]);

        foreach (range(1, 7) as $weekday) {
            $pattern->days()->create(['weekday' => $weekday, 'is_working' => $weekday <= 5, 'expected_hours' => $weekday <= 5 ? 8 : null, 'start_time' => $weekday <= 5 ? '09:00' : null]);
        }

        app(WorkPatternResolver::class)->flush();

        return $pattern;
    }

    public function test_approved_overtime_lands_on_the_day_and_the_request_says_whether_it_is_paid(): void
    {
        $request = OvertimeRequest::create(['employee_id' => $this->employee->getKey(), 'worked_on' => '2026-11-04', 'minutes' => 90, 'reason' => 'Month end']);
        $this->assertSame('Not yet decided', $request->paymentNote());

        try {
            app(OvertimeRequests::class)->approve($request, $this->employee->user);
            $this->fail('Self-approved.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('person who worked it', $e->getMessage());
        }

        app(OvertimeRequests::class)->approve($request, auth()->user());
        $day = AttendanceDay::forDate($this->employee->getKey(), Carbon::parse('2026-11-04'));
        $this->assertSame(90, (int) $day->overtime_minutes);
        $this->assertSame(AttendanceDay::STATUS_PRESENT, $day->status);
        $this->assertStringContainsString('switched off', $request->fresh()->paymentNote(), 'off by default, and the request says so');

        app(TenantSettings::class)->set('payroll.pay_overtime', true);
        $this->assertSame('Paid with the November 2026 payslip', $request->fresh()->paymentNote());

        $second = OvertimeRequest::create(['employee_id' => $this->employee->getKey(), 'worked_on' => '2026-11-04', 'minutes' => 30, 'reason' => 'More']);
        app(OvertimeRequests::class)->approve($second, auth()->user());
        $this->assertSame(120, (int) $day->fresh()->overtime_minutes, 'minutes add up on the day');

        $refused = OvertimeRequest::create(['employee_id' => $this->employee->getKey(), 'worked_on' => '2026-11-03', 'minutes' => 30, 'reason' => 'x']);
        app(OvertimeRequests::class)->refuse($refused, auth()->user(), 'Not authorised');
        $this->assertSame('Not paid: refused', $refused->fresh()->paymentNote());
    }

    public function test_the_patterns_break_and_grace_shape_the_day(): void
    {
        $this->pattern(break: 60, grace: 30);
        $recorder = app(AttendanceRecorder::class);

        // 09:20 is inside a 30-minute grace; 10 hours on the clock less an hour's break is nine, one of overtime.
        $day = $recorder->record($this->employee, '2026-11-03', AttendanceDay::STATUS_PRESENT, '09:20', '19:20');
        $this->assertSame(0, (int) $day->late_minutes);
        $this->assertSame(540, (int) $day->worked_minutes);
        $this->assertSame(60, (int) $day->overtime_minutes);

        $late = $recorder->record($this->employee, '2026-11-04', AttendanceDay::STATUS_PRESENT, '09:45', '18:00');
        $this->assertSame(15, (int) $late->late_minutes, 'fifteen past the pattern\'s grace');
    }

    public function test_a_rest_day_swap_is_what_the_resolver_answers(): void
    {
        $this->pattern();
        $resolver = app(WorkPatternResolver::class);
        $this->assertFalse($resolver->isWorkingDay($this->employee, '2026-11-07'), 'Saturday');
        $this->assertTrue($resolver->isWorkingDay($this->employee, '2026-11-09'), 'Monday');

        RestDaySwap::create(['employee_id' => $this->employee->getKey(), 'work_on' => '2026-11-07', 'rest_on' => '2026-11-09', 'reason' => 'Exhibition']);

        $this->assertTrue($resolver->isWorkingDay($this->employee, '2026-11-07'));
        $this->assertFalse($resolver->isWorkingDay($this->employee, '2026-11-09'));
        $this->assertTrue($resolver->isWorkingDay($this->employee, '2026-11-10'), 'the rest of the week is untouched');
    }

    public function test_missing_punch_reminders_are_off_until_switched_on(): void
    {
        $recorder = app(AttendanceRecorder::class);
        $recorder->record($this->employee, '2026-11-05', AttendanceDay::STATUS_PRESENT, '09:00', null);

        $this->artisan('attendance:remind-missing-punches', ['--tenant' => [$this->tenant->getKey()]])->expectsOutputToContain('off for this company')->assertSuccessful();
        Notification::assertNothingSent();

        app(TenantSettings::class)->set('attendance.remind_missing_punches', true);
        $this->artisan('attendance:remind-missing-punches', ['--tenant' => [$this->tenant->getKey()]])->expectsOutputToContain('1 reminder(s) sent')->assertSuccessful();
        Notification::assertSentTo($this->employee->user, RecordChanged::class);
    }

    public function test_holidays_have_a_kind_roll_over_and_import(): void
    {
        Holiday::create(['date' => '2026-12-25', 'name' => 'Quaid Day', 'kind' => Holiday::KIND_PUBLIC, 'is_recurring' => true]);
        Holiday::create(['date' => '2026-12-31', 'name' => 'Year end (optional)', 'kind' => Holiday::KIND_OPTIONAL, 'is_recurring' => false]);
        app(HolidayCalendar::class)->flush();

        $this->assertTrue(app(HolidayCalendar::class)->isHoliday('2026-12-25'));
        $this->assertFalse(app(HolidayCalendar::class)->isHoliday('2026-12-31'), 'an optional holiday closes nothing');

        $this->artisan('holidays:rollover', ['--tenant' => [$this->tenant->getKey()], '--year' => 2027])->expectsOutputToContain('1 holiday(s) created for 2027')->assertSuccessful();
        $this->assertTrue(Holiday::query()->whereDate('date', '2027-12-25')->exists());
        $this->assertFalse(Holiday::query()->whereDate('date', '2027-12-31')->exists(), 'not recurring, not rolled');
        $this->artisan('holidays:rollover', ['--tenant' => [$this->tenant->getKey()], '--year' => 2027])->expectsOutputToContain('0 holiday(s)')->assertSuccessful();

        $csv = "date,name,kind,recurring,location\n2027-03-23,Pakistan Day,public,yes,\n2027-08-14,Independence Day,,yes,\nnot-a-date,x,,,\n2027-12-25,Dup,,,\n";
        $preview = app(CsvImportService::class)->preview($csv, 'holidays');
        $this->assertSame(2, $preview['ready']);
        $this->assertSame(2, $preview['skipped']);
        $this->assertSame(2, app(CsvImportService::class)->import($csv, 'holidays')['imported']);
        $this->assertSame(Holiday::KIND_PUBLIC, Holiday::query()->whereDate('date', '2027-08-14')->first()->kind, 'blank kind is public');
        $this->assertTrue(app(HolidayCalendar::class)->isHoliday('2027-03-23'));
    }
}
