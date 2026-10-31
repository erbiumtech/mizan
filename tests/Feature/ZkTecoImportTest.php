<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceImport;
use App\Modules\Attendance\Services\ZkTecoAttendanceLog;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The ZKTeco preset — docs/saas-launch-plan.md §7.8. One row per punch in,
 * one row per person per day out, marked as the device's.
 */
class ZkTecoImportTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const EXPORT = <<<'CSV'
    "AC-No.","No.","Name","Time","State","New State","Exception","Operation"
    "7","7","Ayesha","2026-10-05 08:52:10","C/In","","",""
    "7","7","Ayesha","2026-10-05 13:01:44","C/In","","",""
    "7","7","Ayesha","2026-10-05 18:07:03","C/In","","",""
    "7","7","Ayesha","2026-10-06 09:31:00","C/In","","",""
    "12","12","Bilal","2026-10-05 09:05:30","C/In","","",""
    "12","12","Bilal","2026-10-05 17:58:12","C/Out","","",""
    "99","99","Ghost","2026-10-05 10:00:00","C/In","","",""
    CSV;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['attendance', 'employees'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        foreach (['7' => 'Ayesha', '12' => 'Bilal'] as $code => $name) {
            Employee::create(['employee_id' => $code, 'name' => $name, 'gender' => 'Female', 'is_active' => true, 'date_of_joining' => '2020-01-01']);
        }
    }

    public function test_punches_fold_into_days_with_the_first_and_last_as_the_times(): void
    {
        $folded = ZkTecoAttendanceLog::fold(self::EXPORT);

        $this->assertStringContainsString('7,2026-10-05,present,08:52:10,18:07:03,3 punches', $folded);
        $this->assertStringContainsString('7,2026-10-06,present,09:31:00,,1 punch', $folded);
        $this->assertStringContainsString('12,2026-10-05,present,09:05:30,17:58:12,2 punches', $folded);
    }

    public function test_the_preset_imports_as_the_devices_rows_and_names_the_unknown_number(): void
    {
        $import = app(AttendanceImport::class);

        $preview = $import->preview(self::EXPORT, AttendanceImport::PRESET_ZKTECO);
        $this->assertSame(3, $preview->accepted);
        $this->assertCount(1, $preview->errors);
        $this->assertStringContainsString('"99"', $preview->errors[0]);
        $this->assertSame(0, AttendanceDay::count(), 'A preview wrote rows.');

        $result = $import->import(self::EXPORT, AttendanceImport::PRESET_ZKTECO);
        $this->assertSame(3, $result->accepted);
        $this->assertSame(3, AttendanceDay::count());

        $ayesha = Employee::where('employee_id', '7')->firstOrFail();
        $monday = AttendanceDay::forDate($ayesha->getKey(), '2026-10-05');
        $this->assertSame(AttendanceDay::SOURCE_DEVICE, $monday->source);
        $this->assertSame(AttendanceDay::STATUS_PRESENT, $monday->status);
        $this->assertSame('08:52', $monday->check_in_at->format('H:i'));
        $this->assertSame('18:07', $monday->check_out_at->format('H:i'));
        $this->assertSame(554, $monday->worked_minutes);

        $tuesday = AttendanceDay::forDate($ayesha->getKey(), '2026-10-06');
        $this->assertNotNull($tuesday->check_in_at);
        $this->assertNull($tuesday->check_out_at, 'A lone punch invented a check-out.');

        // Re-sending the same export changes nothing: the recorder is idempotent per day.
        $import->import(self::EXPORT, AttendanceImport::PRESET_ZKTECO);
        $this->assertSame(3, AttendanceDay::count());
    }

    public function test_a_file_that_is_not_a_device_log_is_refused_with_the_columns_it_needs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AC-No.');

        ZkTecoAttendanceLog::fold("employee_id,date,status\n7,2026-10-05,present\n");
    }

    public function test_the_plain_preset_is_unchanged(): void
    {
        $result = app(AttendanceImport::class)->import("employee_id,date,status,check_in,check_out,note\n7,2026-10-05,P,09:00,17:00,\n");

        $this->assertSame(1, $result->accepted);
        $this->assertSame(AttendanceDay::SOURCE_IMPORT, AttendanceDay::first()->source);
    }
}
