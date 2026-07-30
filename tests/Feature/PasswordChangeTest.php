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
        $user->companies()->attach($this->tenant);

        $this->get('/admin/profile')
            ->assertSuccessful()
            ->assertSee('Change Password');
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

    public function test_name_and_email_cannot_be_changed_from_the_profile_page(): void
    {
        $user = $this->employee();

        Livewire::test(EditProfile::class)
            ->set('data.name', 'Hacked Name')
            ->set('data.email', 'hacked@test.local')
            ->set('data.currentPassword', 'old-password')
            ->set('data.password', 'new-password-123')
            ->set('data.passwordConfirmation', 'new-password-123')
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame('Employee', $user->name);
        $this->assertSame('employee-password@test.local', $user->email);
    }
}
