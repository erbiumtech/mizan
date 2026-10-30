<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Manager and CEO start with the HR decisions — the role_grants in the Leave, Attendance and Lifecycle
 * manifests.
 *
 * They started with none: only an Administrator could approve leave, a correction, overtime, a document or
 * a final settlement, so a leave chain naming Manager (Company Settings → Leave) waited for ever in a fresh
 * company. The Accountant still records and does not decide, and the Employee still only asks.
 */
class HrApproverRoleGrantsTest extends TestCase
{
    use RefreshDatabase;

    private const DECISIONS = [
        'LeaveRequestApprove',
        'AttendanceRegularizationApprove',
        'ChecklistUpdate',
        'EmployeeDocumentUpdate',
        'SettlementApprove',
    ];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->company = Company::factory()->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        (new RoleSeeder)->run();
    }

    private function grants(string $role)
    {
        return Role::query()->where('company_id', $this->company->id)->where('name', $role)->firstOrFail()->permissions->pluck('name');
    }

    public function test_manager_and_ceo_can_decide_leave_attendance_and_lifecycle(): void
    {
        foreach (['Manager', 'CEO'] as $role) {
            $grants = $this->grants($role);

            foreach ([...self::DECISIONS, 'LeaveRequestView', 'AttendanceView', 'AttendanceRegularizationView', 'ChecklistView', 'EmployeeDocumentView', 'SettlementView'] as $permission) {
                $this->assertTrue($grants->contains($permission), "$role should hold $permission");
            }
        }
    }

    public function test_creating_and_deleting_stay_with_the_administrator(): void
    {
        $manager = $this->grants('Manager');

        foreach (['ChecklistCreate', 'ChecklistDelete', 'SettlementCreate', 'SettlementDelete', 'EmployeeDocumentDelete', 'LeaveTypeCreate'] as $permission) {
            $this->assertFalse($manager->contains($permission), "Manager should not hold $permission");
        }
    }

    public function test_the_accountant_and_the_employee_still_do_not_decide(): void
    {
        foreach (['Accountant', 'Employee'] as $role) {
            $grants = $this->grants($role);

            foreach (self::DECISIONS as $permission) {
                $this->assertFalse($grants->contains($permission), "$role should not hold $permission");
            }
        }
    }
}
