<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceCalendar;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\Holiday;
use App\Modules\Core\Models\User;
use App\Modules\Core\Models\WorkLocation;
use App\Modules\Core\Services\HolidayCalendar;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveDayGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Locations and the calendar — docs/saas-launch-plan.md §7.9. A local holiday
 * is a holiday for that location's employees and for nobody else; a caller
 * passing no location gets the company-wide set, which is every call that
 * existed before.
 */
class LocationCalendarTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private WorkLocation $lahore;

    private Employee $inLahore;

    private Employee $inKarachi;

    private Employee $nowhere;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['attendance', 'employees', 'leave'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->lahore = WorkLocation::create(['name' => 'Lahore', 'code' => 'LHR']);
        $karachi = WorkLocation::create(['name' => 'Karachi', 'code' => 'KHI']);

        $this->inLahore = $this->employee('EMP-1', $this->lahore->getKey());
        $this->inKarachi = $this->employee('EMP-2', $karachi->getKey());
        $this->nowhere = $this->employee('EMP-3', null);

        // A Wednesday, company-wide; a Thursday, Lahore only.
        Holiday::create(['date' => '2026-11-04', 'name' => 'Iqbal Day']);
        Holiday::create(['date' => '2026-11-05', 'name' => 'Lahore local', 'location_id' => $this->lahore->getKey()]);
    }

    private function employee(string $code, ?int $locationId): Employee
    {
        return Employee::create([
            'employee_id' => $code, 'name' => $code, 'gender' => 'Male', 'is_active' => true,
            'date_of_joining' => '2020-01-01', 'location_id' => $locationId,
        ]);
    }

    public function test_a_local_holiday_counts_for_its_location_and_nobody_else(): void
    {
        $calendar = app(HolidayCalendar::class);

        $this->assertTrue($calendar->isHoliday('2026-11-04'));
        $this->assertTrue($calendar->isHoliday('2026-11-04', $this->lahore->getKey()));
        $this->assertTrue($calendar->isHoliday('2026-11-04', $this->inKarachi->location_id));

        $this->assertTrue($calendar->isHoliday('2026-11-05', $this->lahore->getKey()));
        $this->assertFalse($calendar->isHoliday('2026-11-05', $this->inKarachi->location_id));
        $this->assertFalse($calendar->isHoliday('2026-11-05'), 'A caller passing no location got a local holiday.');

        $this->assertSame(['2026-11-04'], $calendar->datesBetween('2026-11-01', '2026-11-30'));
        $this->assertSame(['2026-11-04', '2026-11-05'], $calendar->datesBetween('2026-11-01', '2026-11-30', $this->lahore->getKey()));
    }

    public function test_the_attendance_month_marks_the_local_holiday_for_the_right_people(): void
    {
        $calendar = app(AttendanceCalendar::class);

        foreach ([$this->inLahore, $this->inKarachi, $this->nowhere] as $employee) {
            $calendar->scaffold($employee, 2026, 11);
        }

        $status = fn (Employee $e, string $date): ?string => AttendanceDay::forDate($e->getKey(), $date)?->status;

        $this->assertSame(AttendanceDay::STATUS_HOLIDAY, $status($this->inLahore, '2026-11-04'));
        $this->assertSame(AttendanceDay::STATUS_HOLIDAY, $status($this->inKarachi, '2026-11-04'));
        $this->assertSame(AttendanceDay::STATUS_HOLIDAY, $status($this->nowhere, '2026-11-04'));

        $this->assertSame(AttendanceDay::STATUS_HOLIDAY, $status($this->inLahore, '2026-11-05'));
        $this->assertSame(AttendanceDay::STATUS_NOT_MARKED, $status($this->inKarachi, '2026-11-05'));
        $this->assertSame(AttendanceDay::STATUS_NOT_MARKED, $status($this->nowhere, '2026-11-05'));
    }

    public function test_leave_days_skip_the_local_holiday_only_at_that_location(): void
    {
        $type = LeaveType::create(['code' => 'annual', 'label' => 'Annual', 'days_per_year' => 14, 'accrual_method' => LeaveType::ACCRUAL_ANNUAL_UPFRONT]);

        $plan = function (Employee $employee) use ($type): array {
            $request = new LeaveRequest(['from_date' => '2026-11-02', 'to_date' => '2026-11-06', 'is_half_day' => false]);
            $request->setRelation('employee', $employee);
            $request->setRelation('leaveType', $type);

            return array_column(app(LeaveDayGenerator::class)->plan($request), 'date');
        };

        // Monday to Friday: the national holiday drops out for everybody, the local one for Lahore alone.
        $this->assertSame(['2026-11-02', '2026-11-03', '2026-11-06'], $plan($this->inLahore));
        $this->assertSame(['2026-11-02', '2026-11-03', '2026-11-05', '2026-11-06'], $plan($this->inKarachi));
        $this->assertSame(['2026-11-02', '2026-11-03', '2026-11-05', '2026-11-06'], $plan($this->nowhere));
    }
}
