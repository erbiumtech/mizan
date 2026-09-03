<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Bank;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\Employees\Pages\EditEmployee;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeRequest;
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

    /**
     * Giving an existing employee a login — from an account that is not privileged.
     *
     * `user_id` used to be read from the *pending* attributes to decide whose record this was, so setting it
     * to the actor's own login read as a self-service edit. The field is not requestable, so it was filtered
     * out, no change request was worth filing, and the whole save was reverted: the link and everything
     * saved beside it disappeared without an error. Nothing in the panel does this — the form's user picker
     * is disabled — so it was the importer, a command or a listener that lost the write.
     */
    public function test_linking_an_employee_to_the_actors_own_login_is_not_a_self_service_edit(): void
    {
        [$user] = $this->makeEmployee('Employee');
        $this->actingAs($user);

        $unlinked = Employee::create([
            'employee_id' => 'EMP-NO-LOGIN',
            'name' => 'No login yet',
            'gender' => 'Male',
            'is_active' => true,
        ]);

        $unlinked->update([
            'user_id' => $user->id,
            'phone' => '03001234567',
        ]);

        $unlinked->refresh();
        $this->assertSame($user->id, $unlinked->user_id, 'the link must be written');
        $this->assertSame('03001234567', $unlinked->phone, 'and the rest of the save must not be discarded with it');
        $this->assertSame(0, EmployeeChangeRequest::count());
    }

    /**
     * The mirror of the same bug, and the one that mattered.
     *
     * An employee editing their own record while moving `user_id` to somebody else read as NOT self-service
     * — because the pending value was no longer theirs — so that save applied directly and skipped approval
     * for every other field in it. Ownership is judged from the stored value now, so the edit routes.
     */
    public function test_an_employee_cannot_escape_approval_by_moving_their_own_link(): void
    {
        [$user, $emp] = $this->makeEmployee('Employee');
        $somebodyElse = User::factory()->create();
        $this->actingAs($user);

        $emp->update([
            'user_id' => $somebodyElse->id,
            'phone' => '03009999999',
        ]);

        $emp->refresh();
        $this->assertSame($user->id, $emp->user_id, 'the link must not move');
        $this->assertSame('ORIG'.$user->id, $emp->phone, 'and the edit beside it must not apply directly');

        // The requestable half of the save is still captured rather than dropped.
        $this->assertSame(['phone' => '03009999999'], EmployeeChangeRequest::sole()->requested_changes);
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
