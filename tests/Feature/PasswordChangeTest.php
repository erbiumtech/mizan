<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Pages\Auth\EditProfile;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private function employee(): User
    {
        Role::findOrCreate('Employee', 'web');

        $user = User::create([
            'name' => 'Employee',
            'email' => 'employee-password@test.local',
            'password' => bcrypt('old-password'),
            'status' => 1,
        ]);
        $user->assignRole('Employee');

        $this->actingAs($user);
        $this->setCurrentTenant();

        return $user;
    }

    public function test_profile_page_renders_for_an_employee(): void
    {
        $user = $this->employee();

        $this->get('/admin/profile')
            ->assertSuccessful()
            ->assertSee('My Profile');
    }

    public function test_employee_can_change_their_own_password(): void
    {
        $user = $this->employee();

        Livewire::test(EditProfile::class)
            ->set('data.currentPassword', 'old-password')
            ->set('data.password', 'new-password-123')
            ->set('data.passwordConfirmation', 'new-password-123')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-password-123', $user->refresh()->password));
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = $this->employee();

        Livewire::test(EditProfile::class)
            ->set('data.currentPassword', 'not-my-password')
            ->set('data.password', 'new-password-123')
            ->set('data.passwordConfirmation', 'new-password-123')
            ->call('save')
            ->assertHasErrors('data.currentPassword');

        $this->assertTrue(Hash::check('old-password', $user->refresh()->password));
    }

    public function test_confirmation_must_match(): void
    {
        $user = $this->employee();

        Livewire::test(EditProfile::class)
            ->set('data.currentPassword', 'old-password')
            ->set('data.password', 'new-password-123')
            ->set('data.passwordConfirmation', 'something-else')
            ->call('save')
            ->assertHasErrors('data.password');

        $this->assertTrue(Hash::check('old-password', $user->refresh()->password));
    }

    public function test_a_user_without_an_employee_record_can_update_their_name(): void
    {
        // The role-Employee fixture has no employee *record*, so no approval flow
        // owns their name — it is theirs to change. Email stays read-only regardless.
        $user = $this->employee();

        // Current password confirms identity for any profile change — cheap, and
        // it means a stolen session cannot rename the account either.
        Livewire::test(EditProfile::class)
            ->set('data.name', 'New Name')
            ->set('data.email', 'hacked@test.local')
            ->set('data.currentPassword', 'old-password')
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('employee-password@test.local', $user->email);
    }

    public function test_a_linked_employees_name_is_read_only(): void
    {
        $user = $this->employee();
        $company = \Filament\Facades\Filament::getTenant() ?? \App\Modules\Core\Models\Company::current();

        \App\Modules\Core\Models\CompanyModule::updateOrCreate(
            ['company_id' => $company->getKey(), 'module' => 'employees'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        \App\Modules\Employees\Models\Employee::create([
            'user_id' => $user->id, 'name' => 'Employee', 'employee_id' => 'EMP-PROFILE-1',
            'phone' => '0300-0000000', 'gender' => 'Male', 'is_active' => 1,
        ]);

        // The field is disabled and not dehydrated, so a submitted name is ignored:
        // the change has to go through the employee record's approval flow.
        Livewire::test(EditProfile::class)
            ->set('data.name', 'Bypassed Approval')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Employee', $user->refresh()->name);
    }
}
