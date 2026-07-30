<?php

namespace Tests\Feature;

use App\Modules\Payroll\Filament\Resources\AnnualTaxes\AnnualTaxResource;
use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\EmployeeSettingResource;
use App\Modules\Mpr\Filament\Resources\MPRs\MPRResource;
use App\Modules\Payroll\Filament\Resources\Payslips\PayslipResource;
use App\Modules\Payroll\Models\AnnualTax;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Mpr\Models\MPR;
use App\Modules\Payroll\Models\Payslip;
use Tests\AccountingTestCase;

class EmployeeHierarchyScopingTest extends AccountingTestCase
{
    private Employee $manager;

    private Employee $report;

    private Employee $leaf;

    private Employee $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        // manager -> report -> leaf ; stranger is unrelated.
        $this->manager = $this->makeEmployee('mgr@test.local', 'EMP-M');
        $this->report = $this->makeEmployee('rep@test.local', 'EMP-R', $this->manager->id);
        $this->leaf = $this->makeEmployee('leaf@test.local', 'EMP-L', $this->report->id);
        $this->stranger = $this->makeEmployee('str@test.local', 'EMP-S');
    }

    private function makeEmployee(string $email, string $employeeId, ?int $managerId = null): Employee
    {
        $user = $this->makeUser('Employee', $email);

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

    private function payslipFor(Employee $employee): Payslip
    {
        return Payslip::create([
            'employee_id' => $employee->id,
            'month' => 'January',
            'fiscal_year_id' => $this->fiscalYear->id,
        ]);
    }

    public function test_manager_sees_own_and_full_downline_employees(): void
    {
        $this->actingAs($this->manager->user);

        $ids = EmployeeResource::getEloquentQuery()->pluck('id')->all();

        $this->assertEqualsCanonicalizing(
            [$this->manager->id, $this->report->id, $this->leaf->id],
            $ids
        );
        $this->assertNotContains($this->stranger->id, $ids);
    }

    public function test_leaf_employee_sees_only_self(): void
    {
        $this->actingAs($this->leaf->user);

        $this->assertEqualsCanonicalizing(
            [$this->leaf->id],
            EmployeeResource::getEloquentQuery()->pluck('id')->all()
        );
    }

    public function test_manager_sees_downline_payslips(): void
    {
        $this->payslipFor($this->manager);
        $this->payslipFor($this->leaf);
        $this->payslipFor($this->stranger);

        $this->actingAs($this->manager->user);

        $employeeIds = PayslipResource::getEloquentQuery()->pluck('employee_id')->all();

        $this->assertEqualsCanonicalizing([$this->manager->id, $this->leaf->id], $employeeIds);
    }

    public function test_manager_sees_downline_mprs(): void
    {
        MPR::create(['user_id' => $this->manager->user_id]);
        MPR::create(['user_id' => $this->leaf->user_id]);
        MPR::create(['user_id' => $this->stranger->user_id]);

        $this->actingAs($this->manager->user);

        $userIds = MPRResource::getEloquentQuery()->pluck('user_id')->all();

        $this->assertEqualsCanonicalizing([$this->manager->user_id, $this->leaf->user_id], $userIds);
    }

    public function test_manager_sees_downline_settings_and_taxes(): void
    {
        foreach ([$this->manager, $this->leaf, $this->stranger] as $employee) {
            EmployeeSetting::create([
                'employee_id' => $employee->id,
                'fiscal_year_id' => $this->fiscalYear->id,
                'start_date' => '2025-01-01',
                'basic_wage' => 1000,
            ]);
            AnnualTax::create([
                'employee_id' => $employee->id,
                'fiscal_year_id' => $this->fiscalYear->id,
            ]);
        }

        $this->actingAs($this->manager->user);

        $this->assertEqualsCanonicalizing(
            [$this->manager->id, $this->leaf->id],
            EmployeeSettingResource::getEloquentQuery()->pluck('employee_id')->all()
        );
        $this->assertEqualsCanonicalizing(
            [$this->manager->id, $this->leaf->id],
            AnnualTaxResource::getEloquentQuery()->pluck('employee_id')->all()
        );
    }

    public function test_privileged_role_sees_everything(): void
    {
        $admin = $this->makeUser('Administrator', 'admin@test.local');
        $this->actingAs($admin);

        $this->assertCount(4, EmployeeResource::getEloquentQuery()->get());
    }
}
