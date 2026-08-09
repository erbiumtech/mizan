<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Modules\Employees\Models\Employee;
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
                // Both bank identifiers, because neither is filled: the rule is "at least
                // one", and which one is needed depends on the bank.
                'bank_account_no' => 'required',
                'iban_no' => 'required',
                // No bank chosen, so the short code has to say who they bank with.
                'bank_short_code' => 'required',
                'nic_front' => 'required',
                'nic_back' => 'required',
            ]);
    }

    /**
     * A bank from the directory is not required, because the directory lists the banks we
     * transfer *out* to — an employee who banks with us has none to choose, and requiring one
     * made them unrecordable. See OwnBankAccountTest.
     */
    public function test_a_bank_from_the_directory_is_not_required(): void
    {
        Livewire::test(CreateEmployee::class)
            ->fillForm(['bank_id' => null])
            ->call('create')
            ->assertHasNoFormErrors(['bank_id']);
    }

    public function test_one_bank_identifier_satisfies_the_other(): void
    {
        Livewire::test(CreateEmployee::class)
            ->fillForm(['bank_account_no' => '0000001123456702', 'iban_no' => ''])
            ->call('create')
            ->assertHasNoFormErrors(['iban_no']);
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
