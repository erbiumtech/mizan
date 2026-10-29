<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Devices\Filament\Settings\DevicesSettingsSection;
use App\Modules\Devices\Models\AttendanceDevice;
use App\Modules\Devices\Models\DevicePunch;
use App\Modules\Devices\Notifications\DeviceSilent;
use App\Modules\Devices\Services\DevicePunchFolder;
use App\Modules\Employees\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Biometric devices, live — docs/saas-launch-plan.md §8.7.
 *
 * The device connects to us. The claims: a registered serial is answered and an
 * unknown one is not; every punch is kept once, raw; folding hands first and
 * last punch to the recorder as the device's check-in and check-out and is
 * idempotent; a PIN nobody carries stays unfolded and is listed; a quiet device
 * is reported once.
 */
class DevicePushTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $admin;

    private AttendanceDevice $device;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 20:00:00');
        config(['multitenancy.switch_tenant_tasks' => [\App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class]]);

        $this->seed([\Database\Seeders\PermissionSeeder::class, \Database\Seeders\RoleSeeder::class]);

        $this->admin = User::factory()->create(['status' => 1, 'email' => 'devices-admin@test.local']);
        $this->actingAs($this->admin);
        $this->setCurrentTenant();
        $this->admin->assignRole('Administrator');

        foreach (['devices', 'attendance', 'employees'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->device = AttendanceDevice::create(['company_id' => $this->tenant->getKey(), 'serial_no' => 'ZK-GATE-1', 'name' => 'Main gate', 'model' => 'K40']);

        $this->employee = Employee::create([
            'user_id' => $this->admin->getKey(), 'employee_id' => 'EMP-1', 'device_pin' => '007', 'name' => 'Ayesha', 'gender' => 'Female',
            'is_active' => true, 'date_of_joining' => '2020-01-01',
        ]);
    }

    private function push(string $body, string $serial = 'ZK-GATE-1')
    {
        return $this->call('POST', "/iclock/cdata?SN={$serial}&table=ATTLOG&Stamp=9999", [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    private const DAY = "7\t2026-11-05 08:58:00\t0\t1\t0\t0\n7\t2026-11-05 18:03:00\t1\t1\t0\t0\n9\t2026-11-05 09:10:00\t0\t1\t0\t0\n";

    // ───────────────────────────────────────────────────────── the protocol ──

    public function test_a_registered_serial_is_answered_and_an_unknown_one_is_not(): void
    {
        Company::forgetCurrent();

        $this->get('/iclock/cdata?SN=ZK-GATE-1&options=all&pushver=2.4.1')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('GET OPTION FROM: ZK-GATE-1')
            ->assertSee('Realtime=1');

        $this->get('/iclock/getrequest?SN=ZK-GATE-1')->assertOk()->assertSee('OK');

        $this->get('/iclock/cdata?SN=NOBODY&options=all')->assertNotFound();
        $this->get('/iclock/cdata?options=all')->assertNotFound();

        $this->device->update(['is_active' => false]);
        $this->get('/iclock/cdata?SN=ZK-GATE-1&options=all')->assertNotFound();

        $this->assertSame('2026-11-05 20:00:00', $this->device->fresh()->last_seen_at->toDateTimeString(), 'every call marks the device seen');
    }

    public function test_punches_are_kept_once_with_the_raw_line(): void
    {
        Company::forgetCurrent();

        $this->push(self::DAY)->assertOk()->assertSee('OK: 3');
        $this->push(self::DAY)->assertOk()->assertSee('OK: 0', false);
        $this->push("garbage line\n\n7\tnot a time\t0\n")->assertOk()->assertSee('OK: 0', false);

        $this->tenant->makeCurrent();
        $this->assertSame(3, DevicePunch::count());

        $first = DevicePunch::query()->orderBy('punched_at')->first();
        $this->assertSame('7', $first->device_pin);
        $this->assertSame('ZK-GATE-1', $first->serial_no);
        $this->assertSame("7\t2026-11-05 08:58:00\t0\t1\t0\t0", $first->raw);
        $this->assertSame('0', $first->status_code);
        $this->assertNull($first->folded_at);
    }

    public function test_other_tables_are_acknowledged_and_dropped(): void
    {
        Company::forgetCurrent();

        $this->call('POST', '/iclock/cdata?SN=ZK-GATE-1&table=OPERLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "OPLOG 1\t2026-11-05 08:00:00")
            ->assertOk()->assertSee('OK');

        $this->tenant->makeCurrent();
        $this->assertSame(0, DevicePunch::count());
    }

    // ────────────────────────────────────────────────────────────── folding ──

    public function test_folding_hands_first_and_last_punch_to_the_recorder_and_is_idempotent(): void
    {
        Company::forgetCurrent();
        $this->push(self::DAY);
        $this->tenant->makeCurrent();

        $this->artisan('devices:fold', ['--tenant' => [$this->tenant->getKey()]])
            ->expectsOutputToContain('2 punch(es) folded into 1 day(s)')
            ->expectsOutputToContain('PIN 9: 1 punch(es) with no employee')
            ->assertSuccessful();

        $day = AttendanceDay::forDate($this->employee->getKey(), Carbon::parse('2026-11-05'));
        $this->assertNotNull($day);
        $this->assertSame(AttendanceDay::STATUS_PRESENT, $day->status);
        $this->assertSame('08:58:00', $day->check_in_at->format('H:i:s'));
        $this->assertSame('18:03:00', $day->check_out_at->format('H:i:s'));
        $this->assertSame(AttendanceDay::SOURCE_DEVICE, $day->source);
        $this->assertSame('2 punches from the device', $day->note);

        $this->assertSame(1, DevicePunch::unfolded()->count(), 'the unmapped PIN stays unfolded');
        $this->assertSame('9', DevicePunch::unfolded()->sole()->device_pin);

        // The same day again: the device re-sent it. Nothing changes.
        Company::forgetCurrent();
        $this->push(self::DAY);
        $this->tenant->makeCurrent();
        $this->artisan('devices:fold', ['--tenant' => [$this->tenant->getKey()]])->expectsOutputToContain('0 punch(es) folded')->assertSuccessful();
        $this->assertSame(1, AttendanceDay::where('employee_id', $this->employee->getKey())->count());

        // A later punch the same day extends the check-out; the morning is not forgotten.
        Company::forgetCurrent();
        $this->push("7\t2026-11-05 19:30:00\t1\t1\t0\t0\n");
        $this->tenant->makeCurrent();
        $this->artisan('devices:fold', ['--tenant' => [$this->tenant->getKey()]])->expectsOutputToContain('1 punch(es) folded into 1 day(s)')->assertSuccessful();

        $day->refresh();
        $this->assertSame('08:58:00', $day->check_in_at->format('H:i:s'));
        $this->assertSame('19:30:00', $day->check_out_at->format('H:i:s'));
        $this->assertSame('3 punches from the device', $day->note);
    }

    public function test_the_unmapped_list_names_the_pin_and_clears_once_an_employee_carries_it(): void
    {
        Company::forgetCurrent();
        $this->push(self::DAY);
        $this->tenant->makeCurrent();

        $unmapped = app(DevicePunchFolder::class)->unmapped();
        $this->assertCount(1, $unmapped);
        $this->assertSame('9', (string) $unmapped[0]->device_pin);
        $this->assertSame(1, (int) $unmapped[0]->punches);

        Employee::create(['employee_id' => 'EMP-2', 'device_pin' => '9', 'name' => 'Bilal', 'gender' => 'Male', 'is_active' => true, 'date_of_joining' => '2026-11-01']);

        $this->assertCount(0, app(DevicePunchFolder::class)->unmapped());
        app(DevicePunchFolder::class)->fold();
        $this->assertSame(0, DevicePunch::unfolded()->count());
    }

    // ───────────────────────────────────────────────────────────── silence ──

    public function test_a_device_quiet_for_a_day_is_reported_to_the_administrators_once(): void
    {
        Notification::fake();
        $this->device->forceFill(['last_seen_at' => '2026-11-04 08:00:00'])->save();

        $this->artisan('devices:check-silence')->expectsOutputToContain('Main gate (ZK-GATE-1) has been quiet')->assertSuccessful();
        Notification::assertSentTo($this->admin, DeviceSilent::class, fn (DeviceSilent $n): bool => $n->serialNo === 'ZK-GATE-1');
        Notification::assertCount(1);

        // Told once per silence.
        $this->artisan('devices:check-silence')->assertSuccessful();
        Notification::assertCount(1);

        // The device calls in: the mark clears, and a later silence is reported afresh.
        Company::forgetCurrent();
        $this->get('/iclock/getrequest?SN=ZK-GATE-1')->assertOk();
        $this->assertNull($this->device->fresh()->silence_notified_at);

        Carbon::setTestNow('2026-11-07 20:00:00');
        $this->artisan('devices:check-silence')->assertSuccessful();
        Notification::assertCount(2);
    }

    public function test_a_device_seen_today_is_not_reported(): void
    {
        Notification::fake();
        $this->device->forceFill(['last_seen_at' => '2026-11-05 07:00:00'])->save();

        $this->artisan('devices:check-silence')->expectsOutputToContain('0 administrator(s) told')->assertSuccessful();
        Notification::assertNothingSent();
    }

    // ──────────────────────────────────────────────────────────── registry ──

    public function test_the_settings_section_registers_this_companys_devices_and_cannot_take_anothers(): void
    {
        $other = Company::factory()->create();
        AttendanceDevice::create(['company_id' => $other->getKey(), 'serial_no' => 'ZK-THEIRS', 'name' => 'Their gate']);

        $section = new DevicesSettingsSection;
        $this->assertSame(['ZK-GATE-1'], array_column($section->fill()['devices_registry'], 'serial_no'));

        $section->save(['devices_registry' => [
            ['serial_no' => 'ZK-GATE-1', 'name' => 'Main gate (renamed)', 'model' => 'K40', 'is_active' => false],
            ['serial_no' => 'ZK-FLOOR-2', 'name' => 'Second floor', 'model' => '', 'is_active' => true],
            ['serial_no' => 'ZK-THEIRS', 'name' => 'Not mine', 'model' => '', 'is_active' => true],
        ]]);

        $mine = AttendanceDevice::query()->where('company_id', $this->tenant->getKey())->orderBy('id')->get();
        $this->assertSame(['ZK-GATE-1', 'ZK-FLOOR-2'], $mine->pluck('serial_no')->all());
        $this->assertSame('Main gate (renamed)', $mine[0]->name);
        $this->assertFalse($mine[0]->is_active);
        $this->assertSame((int) $other->getKey(), (int) AttendanceDevice::where('serial_no', 'ZK-THEIRS')->value('company_id'), 'another company\'s serial stays theirs');

        // Dropping a row from the repeater removes the registration.
        $section->save(['devices_registry' => [['serial_no' => 'ZK-FLOOR-2', 'name' => 'Second floor', 'is_active' => true]]]);
        $this->assertSame(['ZK-FLOOR-2'], AttendanceDevice::query()->where('company_id', $this->tenant->getKey())->pluck('serial_no')->all());
    }
}
