<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceRecorder;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Core\Models\WorkLocation;
use App\Modules\Employees\Models\Employee;
use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The geofence — docs/saas-launch-plan.md §7.9. Off records and judges
 * nothing; flag records and marks; refuse refuses and the regularization path
 * stays; no radius means no judgement in any mode.
 */
class GeofenceTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const OFFICE = ['latitude' => 31.5204, 'longitude' => 74.3587];

    private const NEXT_DOOR = ['latitude' => 31.5210, 'longitude' => 74.3590, 'accuracy_m' => 15];

    private const ACROSS_TOWN = ['latitude' => 31.4700, 'longitude' => 74.4100, 'accuracy_m' => 15];

    private WorkLocation $office;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-02 09:00:00');

        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);
        $this->setCurrentTenant();

        foreach (['attendance', 'employees'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->office = WorkLocation::create(['name' => 'Head office'] + self::OFFICE + ['geofence_radius_m' => 200]);
        $this->employee = Employee::create([
            'user_id' => $user->getKey(), 'employee_id' => 'EMP-1', 'name' => 'Ayesha', 'gender' => 'Female',
            'is_active' => true, 'date_of_joining' => '2020-01-01', 'location_id' => $this->office->getKey(),
        ]);
    }

    private function mode(string $mode): void
    {
        app(TenantSettings::class)->set('attendance.geofence_mode', $mode);
    }

    private function clock(?array $position): AttendanceDay
    {
        return app(AttendanceRecorder::class)->clock($this->employee->fresh(), $position);
    }

    public function test_the_distance_is_what_a_map_would_say(): void
    {
        $this->assertLessThan(100, $this->office->distanceTo(self::NEXT_DOOR['latitude'], self::NEXT_DOOR['longitude']));
        $this->assertGreaterThan(5000, $this->office->distanceTo(self::ACROSS_TOWN['latitude'], self::ACROSS_TOWN['longitude']));
        $this->assertTrue($this->office->contains(self::NEXT_DOOR['latitude'], self::NEXT_DOOR['longitude']));
        $this->assertFalse($this->office->contains(self::ACROSS_TOWN['latitude'], self::ACROSS_TOWN['longitude']));
        $this->assertNull(WorkLocation::create(['name' => 'No fence'])->contains(0, 0));
    }

    public function test_off_records_and_judges_nothing(): void
    {
        $day = $this->clock(self::ACROSS_TOWN);

        $this->assertNotNull($day->check_in_at);
        $this->assertSame(31.47, (float) $day->latitude);
        $this->assertNull($day->outside_geofence);
    }

    public function test_flag_marks_a_punch_made_outside_and_keeps_the_mark(): void
    {
        $this->mode('flag');

        $day = $this->clock(self::ACROSS_TOWN);
        $this->assertNotNull($day->check_in_at, 'Flag mode refused a punch.');
        $this->assertTrue($day->outside_geofence);

        // Clocking out from inside does not unmark the morning.
        Carbon::setTestNow('2026-11-02 18:00:00');
        $this->assertTrue($this->clock(self::NEXT_DOOR)->outside_geofence);

        // And a punch inside is never marked.
        AttendanceDay::query()->delete();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->assertFalse((bool) $this->clock(self::NEXT_DOOR)->outside_geofence);
    }

    public function test_refuse_refuses_outside_and_without_a_reading_and_accepts_inside(): void
    {
        $this->mode('refuse');

        try {
            $this->clock(self::ACROSS_TOWN);
            $this->fail('A punch across town was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Head office', $e->getMessage());
            $this->assertStringContainsString('regularization', $e->getMessage());
        }

        try {
            $this->clock(null);
            $this->fail('A punch with no reading was accepted in refuse mode.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('no location reading', $e->getMessage());
        }

        $this->assertSame(0, AttendanceDay::count());

        $day = $this->clock(self::NEXT_DOOR);
        $this->assertNotNull($day->check_in_at);
        $this->assertFalse((bool) $day->outside_geofence);

        // The API says 422 with the same words, so the phone can show them.
        AttendanceDay::query()->delete();
        $this->postJson('/api/v1/my-attendance/clock', self::ACROSS_TOWN)
            ->assertStatus(422)
            ->assertJsonFragment(['message' => $this->refusalFor(self::ACROSS_TOWN)]);
    }

    public function test_no_radius_or_no_location_means_no_judgement_in_any_mode(): void
    {
        $this->mode('refuse');

        $this->office->update(['geofence_radius_m' => null]);
        $this->assertNotNull($this->clock(self::ACROSS_TOWN)->check_in_at);
        $this->assertNull(AttendanceDay::first()->outside_geofence);

        AttendanceDay::query()->delete();
        $this->employee->update(['location_id' => null]);
        $this->assertNotNull($this->clock(null)->check_in_at);
    }

    private function refusalFor(array $position): string
    {
        try {
            $this->clock($position);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return '';
    }
}
