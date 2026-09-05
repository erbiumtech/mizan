<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A second factor for the accounts that can move money.
 *
 * Required — the panel refuses to proceed until it is enrolled — for super admins and for anybody holding
 * Administrator in any company. Optional for everyone else, who may still enrol from their profile.
 */
class MultiFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_panels_offer_an_authenticator_app_with_recovery_codes(): void
    {
        foreach (['admin', 'platform'] as $panel) {
            $this->assertTrue(Filament::getPanel($panel)->hasMultiFactorAuthentication(), "[{$panel}] has no second factor");
            $this->assertNotEmpty(Filament::getPanel($panel)->getMultiFactorAuthenticationProviders());
        }
    }

    public function test_the_user_can_hold_a_secret_and_recovery_codes(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(HasAppAuthentication::class, $user);
        $this->assertInstanceOf(HasAppAuthenticationRecovery::class, $user);
        $this->assertTrue(Schema::hasColumn('users', 'app_authentication_secret'));
        $this->assertTrue(Schema::hasColumn('users', 'app_authentication_recovery_codes'));

        // Encrypted at rest: a dump of `users` must not hand out the secrets it protects.
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->fresh()->getAppAuthenticationSecret());
        $this->assertNotSame('JBSWY3DPEHPK3PXP', $user->fresh()->getRawOriginal('app_authentication_secret'));
    }

    public function test_a_super_admin_must_use_it(): void
    {
        $this->assertTrue(User::factory()->create(['is_super_admin' => true])->mustUseMultiFactorAuthentication());
    }

    /**
     * Asked team-agnostically: at the login step there is no company yet, so `hasRole()` would answer "no"
     * for every company administrator on the installation.
     */
    public function test_an_administrator_of_any_company_must_use_it_even_with_no_company_current(): void
    {
        $this->seed(PermissionSeeder::class);
        $company = Company::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        // No company current — the state at the login step.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->assertTrue($admin->fresh()->mustUseMultiFactorAuthentication());
        $this->assertFalse($employee->fresh()->mustUseMultiFactorAuthentication(), 'optional, not forbidden, for everyone else');
    }
}
