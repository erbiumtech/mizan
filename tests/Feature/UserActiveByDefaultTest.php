<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Pages\CompanySettings;
use App\Modules\Core\Filament\Resources\Users\Pages\CreateUser;
use App\Modules\Core\Filament\Resources\Users\Pages\EditUser;
use App\Modules\Core\Filament\Settings\UserAccessSettingsSection;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\CompanyInvitations;
use App\Support\TenantSettings;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * An account made on the Users page could not sign in: `users.status` defaults to 0, the form had no
 * field for it, and User::canAccessPanel() wants 1. Now the form has an Active switch, and what a new
 * account starts as is the company's setting (Company Settings → People → Users), active by default.
 */
class UserActiveByDefaultTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->company = Company::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        (new RoleSeeder)->run();

        $this->admin = User::factory()->create(['status' => 1]);
        $this->company->users()->attach($this->admin);
        $this->admin->assignRole('Administrator');

        $this->actingAs($this->admin);
        $this->setCurrentTenant($this->company);
    }

    private function create(string $email, array $extra = []): User
    {
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'New Person', 'email' => $email, 'password' => 'password123'] + $extra)
            ->call('create')
            ->assertHasNoFormErrors();

        return User::query()->acrossCompanies()->where('email', $email)->sole();
    }

    public function test_a_new_account_is_active_and_can_sign_in_by_default(): void
    {
        $user = $this->create('active@example.test');

        $this->assertSame(1, (int) $user->status);
        $this->assertTrue($user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')));
    }

    public function test_the_company_can_make_new_accounts_start_deactivated(): void
    {
        app(TenantSettings::class)->set(UserAccessSettingsSection::KEY, false);

        Livewire::test(CreateUser::class)->assertSchemaStateSet(['status' => false]);

        $user = $this->create('inactive@example.test');

        $this->assertSame(0, (int) $user->status);
        $this->assertFalse($user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')));
    }

    public function test_the_switch_on_the_form_decides_for_that_account(): void
    {
        $user = $this->create('switched-off@example.test', ['status' => false]);

        $this->assertSame(0, (int) $user->status);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertSchemaStateSet(['status' => false])
            ->fillForm(['status' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, (int) $user->fresh()->status);
    }

    public function test_the_setting_is_saved_from_company_settings(): void
    {
        Livewire::test(CompanySettings::class)
            ->assertSchemaStateSet(['users_new_active' => true])
            ->fillForm(['users_new_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(UserAccessSettingsSection::newAccountsActive());
    }

    public function test_an_invitation_is_active_whatever_the_setting(): void
    {
        Notification::fake();
        app(TenantSettings::class)->set(UserAccessSettingsSection::KEY, false);

        $result = app(CompanyInvitations::class)->invite($this->company, 'invited@example.test', 'Invited Person', ['Employee'], $this->admin);

        $this->assertSame(1, (int) $result['user']->status);
    }
}
