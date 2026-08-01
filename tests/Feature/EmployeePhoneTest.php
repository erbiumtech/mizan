<?php

namespace Tests\Feature;

use App\Modules\Employees\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Modules\Employees\Models\Employee;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class EmployeePhoneTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->setCurrentTenant();
    }

    public function test_required_fields(): void
    {
        Livewire::test(CreateEmployee::class)
            ->fillForm(['phone' => '', 'nic' => '', 'bank_account_no' => '', 'iban_no' => ''])
            ->call('create')
            ->assertHasFormErrors([
                'phone' => 'required',
                'nic' => 'required',
                'bank_id' => 'required',
                'bank_account_no' => 'required',
                'iban_no' => 'required',
                'nic_front' => 'required',
                'nic_back' => 'required',
            ]);
    }

    public function test_phone_must_be_unique(): void
    {
        $u = User::factory()->create();
        Employee::create(['user_id' => $u->id, 'employee_id' => 'EMP-X', 'phone' => '0300-9999999', 'gender' => 'Male', 'is_active' => 1]);

        Livewire::test(CreateEmployee::class)
            ->fillForm(['phone' => '0300-9999999'])
            ->call('create')
            ->assertHasFormErrors(['phone' => 'unique']);
    }
}
