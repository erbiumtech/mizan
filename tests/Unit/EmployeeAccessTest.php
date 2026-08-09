<?php

namespace Tests\Unit;

use App\Modules\Employees\Models\Employee;
use App\Support\EmployeeAccess;
use Tests\AccountingTestCase;

class EmployeeAccessTest extends AccountingTestCase
{
    private function employee(string $role, string $email, string $employeeId, ?int $managerId = null): Employee
    {
        $user = $this->makeUser($role, $email);

        return Employee::create([
            'user_id' => $user->id,
            'manager_id' => $managerId,
            'employee_id' => $employeeId,
            'designation' => 'Backend Developer',
            'department' => 'IT',
            'nic' => '00000-0000000-0',
            'date_of_joining' => '2025-01-01',
            'gender' => 'Male',
            'phone' => 'ph-'.$employeeId,
        ]);
    }

    public function test_manager_sees_self_and_full_downline(): void
    {
        // ceo -> lead -> dev  (three levels)
        $ceo = $this->employee('Employee', 'ceo@a.test', 'EMP-A1');
        $lead = $this->employee('Employee', 'lead@a.test', 'EMP-A2', $ceo->id);
        $dev = $this->employee('Employee', 'dev@a.test', 'EMP-A3', $lead->id);

        $access = app(EmployeeAccess::class);

        $ceoIds = $access->accessibleEmployeeIds($ceo->user)->all();
        $this->assertEqualsCanonicalizing([$ceo->id, $lead->id, $dev->id], $ceoIds);

        // The lead sees themselves + dev, but not the ceo above them.
        $this->assertEqualsCanonicalizing([$lead->id, $dev->id], app(EmployeeAccess::class)->accessibleEmployeeIds($lead->user)->all());

        // A leaf sees only themselves.
        $this->assertEqualsCanonicalizing([$dev->id], app(EmployeeAccess::class)->accessibleEmployeeIds($dev->user)->all());
    }

    public function test_accessible_user_ids_map_from_employees(): void
    {
        $ceo = $this->employee('Employee', 'ceo2@a.test', 'EMP-B1');
        $lead = $this->employee('Employee', 'lead2@a.test', 'EMP-B2', $ceo->id);

        $this->assertEqualsCanonicalizing(
            [$ceo->user_id, $lead->user_id],
            app(EmployeeAccess::class)->accessibleUserIds($ceo->user)->all()
        );
    }

    public function test_user_without_employee_record_gets_empty_set(): void
    {
        $outsider = $this->makeUser('Employee', 'outsider@a.test');

        $this->assertTrue(app(EmployeeAccess::class)->accessibleEmployeeIds($outsider)->isEmpty());
    }

    public function test_scope_helper_restricts_non_privileged_and_passes_privileged(): void
    {
        $mgr = $this->employee('Employee', 'mgr-scope@a.test', 'EMP-D1');
        $rep = $this->employee('Employee', 'rep-scope@a.test', 'EMP-D2', $mgr->id);
        $this->employee('Employee', 'other-scope@a.test', 'EMP-D3');

        $access = app(EmployeeAccess::class);

        // Non-privileged manager: only own + downline.
        $scoped = $access->scopeAccessibleEmployees(Employee::query(), $mgr->user)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$mgr->id, $rep->id], $scoped);

        // Privileged user: no restriction (all four employees visible).
        $admin = $this->makeUser('Administrator', 'admin-scope@a.test');
        $this->assertTrue($access->isPrivileged($admin));
        $this->assertCount(3, $access->scopeAccessibleEmployees(Employee::query(), $admin)->get());
    }

    public function test_deleting_a_manager_reparents_reports_to_grandmanager(): void
    {
        $ceo = $this->employee('Employee', 'ceo3@a.test', 'EMP-C1');
        $lead = $this->employee('Employee', 'lead3@a.test', 'EMP-C2', $ceo->id);
        $dev = $this->employee('Employee', 'dev3@a.test', 'EMP-C3', $lead->id);

        $lead->delete();

        // dev now reports directly to the ceo (lead's own manager).
        $this->assertSame($ceo->id, $dev->fresh()->manager_id);
    }
}
