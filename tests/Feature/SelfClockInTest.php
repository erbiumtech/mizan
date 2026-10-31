<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceRecorder;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Self clock-in with coordinates — docs/saas-launch-plan.md §7.1.
 *
 * The one rule: coordinates are recorded, not judged. A punch from the other
 * side of the world is still a punch, and a punch with no reading at all is too.
 */
class SelfClockInTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 09:07:00');

        $this->user = User::factory()->create(['status' => 1]);
        $this->actingAs($this->user);
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'user_id' => $this->user->getKey(),
            'employee_id' => 'EMP-1',
            'name' => 'Ayesha',
            'gender' => 'Female',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);

        foreach (['attendance', 'employees'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();
    }

    private function recorder(): AttendanceRecorder
    {
        return app(AttendanceRecorder::class);
    }

    public function test_the_first_punch_clocks_in_with_the_reading_and_the_second_clocks_out(): void
    {
        $day = $this->recorder()->clock($this->employee, ['latitude' => 31.5204, 'longitude' => 74.3587, 'accuracy_m' => 12]);

        $this->assertSame('2026-10-28', $day->date->toDateString());
        $this->assertSame(AttendanceDay::STATUS_PRESENT, $day->status);
        $this->assertSame(AttendanceDay::SOURCE_SELF_SERVICE, $day->source);
        $this->assertSame('09:07', $day->check_in_at->format('H:i'));
        $this->assertNull($day->check_out_at);
        $this->assertSame(31.5204, (float) $day->latitude);
        $this->assertSame(74.3587, (float) $day->longitude);
        $this->assertSame(12, $day->accuracy_m);

        Carbon::setTestNow('2026-10-28 18:02:00');
        $day = $this->recorder()->clock($this->employee, ['latitude' => 0.0, 'longitude' => 0.0, 'accuracy_m' => 5000]);

        $this->assertSame('09:07', $day->check_in_at->format('H:i'), 'Clocking out erased the check-in.');
        $this->assertSame('18:02', $day->check_out_at->format('H:i'));
        $this->assertSame(535, $day->worked_minutes);
        // The clock-in's reading is the one kept; the clock-out's is not judged either way.
        $this->assertSame(31.5204, (float) $day->latitude);
        $this->assertSame(1, AttendanceDay::count());
    }

    public function test_a_third_punch_is_refused_and_points_at_regularization(): void
    {
        // Hours apart, as a real day is: punches within a minute of the check-in are one tap
        // (AttendanceRecorder::punch()), so three at the same frozen instant would be one punch.
        $this->recorder()->clock($this->employee);
        Carbon::setTestNow('2026-10-28 18:02:00');
        $this->recorder()->clock($this->employee);
        Carbon::setTestNow('2026-10-28 19:30:00');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('regularization');

        $this->recorder()->clock($this->employee);
    }

    public function test_no_reading_is_an_honest_answer(): void
    {
        $day = $this->recorder()->clock($this->employee, null);

        $this->assertNotNull($day->check_in_at);
        $this->assertNull($day->latitude);
        $this->assertNull($day->longitude);
        $this->assertNull($day->accuracy_m);

        // A reading that arrives at clock-out fills a day that had none.
        Carbon::setTestNow('2026-10-28 18:02:00');
        $day = $this->recorder()->clock($this->employee, ['latitude' => 24.86, 'longitude' => 67.01]);
        $this->assertSame(24.86, (float) $day->latitude);
        $this->assertNull($day->accuracy_m);
    }

    public function test_the_api_clocks_the_caller_and_nobody_else(): void
    {
        $colleague = Employee::create([
            'employee_id' => 'EMP-2', 'name' => 'Bilal', 'gender' => 'Male', 'is_active' => true, 'date_of_joining' => '2020-01-01',
        ]);

        $this->postJson('/api/v1/my-attendance/clock', [
            'latitude' => 31.5204, 'longitude' => 74.3587, 'accuracy_m' => 20,
            // Ignored, not honoured: the punch is the caller's own.
            'employee_id' => $colleague->getKey(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.check_out_at', null)
            ->assertJsonPath('data.source', AttendanceDay::SOURCE_SELF_SERVICE)
            ->assertJsonPath('data.latitude', 31.5204);

        Carbon::setTestNow('2026-10-28 18:02:00');
        $this->postJson('/api/v1/my-attendance/clock')
            ->assertOk()
            ->assertJsonPath('data.date', '2026-10-28');

        $this->assertSame(1, AttendanceDay::where('employee_id', $this->employee->getKey())->count());
        $this->assertSame(0, AttendanceDay::where('employee_id', $colleague->getKey())->count());

        $this->postJson('/api/v1/my-attendance/clock')->assertStatus(422);
        $this->postJson('/api/my-attendance/clock', ['latitude' => 200, 'longitude' => 0])->assertStatus(422);
    }

    public function test_a_caller_without_an_employee_profile_gets_404(): void
    {
        $this->actingAs(User::factory()->create(['status' => 1]));

        $this->postJson('/api/my-attendance/clock')->assertNotFound();
    }
}
