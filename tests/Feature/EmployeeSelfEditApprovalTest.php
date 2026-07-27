<?php

namespace Tests\Feature;

use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Models\Bank;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeChangeRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class EmployeeSelfEditApprovalTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    protected function makeEmployee(string $role): array
    {
        $bank = Bank::firstOrCreate(['bank_code' => 'B1'], ['bank_name' => 'Test Bank', 'is_active' => true]);
        $user = User::factory()->create();
        $user->assignRole(array_unique(['Employee', $role]));
        $emp = Employee::create([
            'user_id' => $user->id, 'employee_id' => 'EMP-'.$user->id, 'phone' => 'ORIG'.$user->id, 'secondary_phone' => '0301'.$user->id, 'gender' => 'Male', 'is_active' => 1,
            'designation' => 'Cook', 'department' => 'Office Staff',
            'nic' => '12345', 'nic_front' => 'nic/a.png', 'nic_back' => 'nic/b.png',
            'bank_id' => $bank->id, 'bank_account_no' => 'ACC1', 'iban_no' => 'IBAN1',
        ]);

        return [$user, $emp];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $company = Company::factory()->create();
        app()->instance('currentTenant', $company);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        (new RoleSeeder)->run();
        Storage::disk('public')->put('nic/a.png', 'x');
        Storage::disk('public')->put('nic/b.png', 'x');
    }

    public function test_plain_employee_edit_is_routed_to_approval(): void
    {
        [$user, $emp] = $this->makeEmployee('Employee');
        $this->actingAs($user);
        $this->setCurrentTenant(app('currentTenant'));

        Livewire::test(EditEmployee::class, ['record' => $emp->id])
            ->fillForm(['phone' => '03009999999'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('ORIG'.$user->id, $emp->fresh()->phone, 'employee edit must NOT save directly');
        $this->assertSame(1, EmployeeChangeRequest::count(), 'a change request must be created');
    }

    public function test_privileged_user_edit_saves_directly(): void
    {
        [$user, $emp] = $this->makeEmployee('Manager');
        $this->actingAs($user);
        $this->setCurrentTenant(app('currentTenant'));

        Livewire::test(EditEmployee::class, ['record' => $emp->id])
            ->fillForm(['phone' => '03009999999'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('03009999999', $emp->fresh()->phone, 'privileged edit saves directly');
        $this->assertSame(0, EmployeeChangeRequest::count());
    }
}
