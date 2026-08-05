<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Who may open the platform panel.
 *
 * This is the whole boundary. The panel has no tenant, so nothing on it is scoped to a
 * company, and `Gate::before` grants a super admin every ability — which means one missing
 * condition in `canAccessPanel()` hands an ordinary active user the installation:
 * every company, every user account, every licence.
 *
 * So the routes are enumerated rather than sampled. A test that checks the index and
 * trusts the rest is a test that passes while one page is open to everybody.
 */
class PlatformPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every GET route the platform panel registers.
     *
     * @return array<int, string>
     */
    private function platformRoutes(): array
    {
        $urls = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'platform') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            // Routes with a record parameter need a record; covered separately below.
            // The login page is the front door and is reachable by design — an
            // authenticated user is redirected off it, which the tests below check
            // lands somewhere that is itself refused.
            if (str_contains($uri, '{') || str_ends_with($uri, '/login')) {
                continue;
            }

            $urls[] = '/'.$uri;
        }

        return array_values(array_unique($urls));
    }

    private function companyAdministrator(): User
    {
        $this->seed(PermissionSeeder::class);

        $company = Company::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        $admin = User::factory()->create();
        $company->users()->attach($admin);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        $admin->assignRole('Administrator');

        return $admin;
    }

    public function test_the_panel_exists_and_has_no_tenancy(): void
    {
        $panel = Filament::getPanel('platform');

        $this->assertFalse($panel->hasTenancy(), 'a company in the URL is the thing this panel does without');
        $this->assertSame('platform', $panel->getPath());
    }

    public function test_the_routes_this_test_guards_actually_exist(): void
    {
        // Otherwise an empty route list would make every assertion below vacuous — the
        // way a security test quietly stops testing anything.
        $this->assertNotEmpty($this->platformRoutes());
    }

    public function test_a_super_admin_may_open_every_platform_route(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($superAdmin);

        foreach ($this->platformRoutes() as $url) {
            // Following redirects, because /platform itself redirects to the panel's
            // first resource — which is reaching it, not being turned away.
            $this->followingRedirects()->get($url)
                ->assertSuccessful("a super admin should be able to open {$url}");
        }
    }

    public function test_a_company_administrator_may_open_none_of_them(): void
    {
        $admin = $this->companyAdministrator();

        $this->actingAs($admin);

        foreach ($this->platformRoutes() as $url) {
            $this->get($url)->assertForbidden("a company administrator must not reach {$url}");
        }
    }

    public function test_an_ordinary_user_may_open_none_of_them(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        foreach ($this->platformRoutes() as $url) {
            $this->get($url)->assertForbidden("an ordinary user must not reach {$url}");
        }
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/platform')->assertRedirect('/platform/login');
    }

    /**
     * The login page redirects anybody already signed in, so it cannot 403 itself. What
     * matters is where that redirect goes: for a user who may not be here, into a refusal.
     */
    public function test_the_login_page_leads_an_ordinary_user_nowhere(): void
    {
        $this->actingAs(User::factory()->create());

        $this->followingRedirects()->get('/platform/login')->assertForbidden();
    }

    public function test_an_inactive_super_admin_is_refused(): void
    {
        // Deactivating an account has to mean it, whoever it belongs to.
        $superAdmin = User::factory()->inactive()->create(['is_super_admin' => true]);

        $this->assertFalse($superAdmin->canAccessPanel(Filament::getPanel('platform')));
    }

    public function test_a_company_administrator_keeps_the_admin_panel(): void
    {
        // The boundary is about which panel, not about taking anything away.
        $admin = $this->companyAdministrator();

        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($admin->canAccessPanel(Filament::getPanel('platform')));
    }

    public function test_a_specific_companys_edit_page_is_refused_to_a_company_administrator(): void
    {
        // The record routes skipped above, where a company administrator reaching one
        // would be reading another customer's company record.
        $admin = $this->companyAdministrator();
        $other = Company::factory()->create();

        $this->actingAs($admin);

        $this->get("/platform/companies/{$other->getKey()}/edit")->assertForbidden();
    }
}
