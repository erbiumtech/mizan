<?php

namespace Tests\Feature;

use App\Filament\Resources\EmployeeSettings\EmployeeSettingResource;
use App\Filament\Resources\EmployeeSettings\Pages\ListEmployeeSettings;
use App\Models\Employee;
use App\Models\EmployeeSetting;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

class EmployeeSettingSelfViewTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    private Employee $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = $this->makeEmployee('self@test.local', 'EMP-SELF');
        $this->colleague = $this->makeEmployee('other@test.local', 'EMP-OTHER');

        foreach ([$this->employee, $this->colleague] as $i => $employee) {
            EmployeeSetting::create([
                'employee_id' => $employee->id,
                'fiscal_year_id' => $this->fiscalYear->id,
                'start_date' => '2025-07-01',
                'basic_wage' => 200000 + $i,
                'medical_allowance' => 15000,
                'petrol_allowance' => 12000,
                'bonus' => 5000,
                'extra_work_hours' => 6,
                'meal_deduction' => 2500,
                'advances' => 10000,
            ]);
        }
    }

    private function makeEmployee(string $email, string $employeeId): Employee
    {
        return Employee::create([
            'user_id' => $this->makeUser('Employee', $email)->id,
            'employee_id' => $employeeId,
            'gender' => 'Male',
            'phone' => 'ph-'.$employeeId,
        ]);
    }

    /** Filament dispatches TenantSet with the current user, so log in first. */
    private function actingAsEmployee(): void
    {
        $this->actingAs($this->employee->user);
        $this->setCurrentTenant();
    }

    public function test_employee_role_can_reach_the_employee_settings_resource(): void
    {
        $this->actingAsEmployee();

        $this->assertTrue($this->employee->user->can('viewAny', EmployeeSetting::class));
        $this->assertTrue(EmployeeSettingResource::canViewAny());
    }

    public function test_employee_sees_only_their_own_settings(): void
    {
        $this->actingAsEmployee();

        $this->assertSame(
            [$this->employee->id],
            EmployeeSettingResource::getEloquentQuery()->pluck('employee_id')->all()
        );

        Livewire::test(ListEmployeeSettings::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(EmployeeSetting::where('employee_id', $this->employee->id)->get())
            ->assertCanNotSeeTableRecords(EmployeeSetting::where('employee_id', $this->colleague->id)->get());
    }

    /**
     * The allowance/deduction columns must render without being un-hidden
     * through the column toggle menu.
     */
    public function test_allowance_and_deduction_columns_are_visible_by_default(): void
    {
        $this->actingAsEmployee();

        $component = Livewire::test(ListEmployeeSettings::class)->assertSuccessful();

        foreach ([
            'basic_wage',
            'medical_allowance',
            'petrol_allowance',
            'bonus',
            'extra_work_hours',
            'meal_deduction',
            'advances',
        ] as $column) {
            $component->assertTableColumnVisible($column);
        }
    }

    /**
     * Employees may edit their own settings — the edit becomes a pending change
     * request rather than a direct write, covered by
     * {@see EmployeeSettingSelfEditApprovalTest}. Creating and deleting stay
     * closed to them, as do a colleague's settings.
     */
    public function test_employee_can_edit_only_their_own_settings_and_cannot_create_or_delete(): void
    {
        $this->actingAsEmployee();

        $this->assertFalse(EmployeeSettingResource::canCreate());

        $own = EmployeeSetting::where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertTrue($this->employee->user->can('update', $own));
        $this->assertFalse($this->employee->user->can('delete', $own));

        $theirs = EmployeeSetting::where('employee_id', $this->colleague->id)->firstOrFail();
        $this->assertFalse($this->employee->user->can('update', $theirs));
    }
}
