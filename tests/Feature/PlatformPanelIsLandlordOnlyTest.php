<?php

namespace Tests\Feature;

use App\Models\TenantModel;
use App\Modules\Core\Models\User;
use App\Support\ModuleMap;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rule the platform panel cannot break: everything on it is landlord-backed.
 *
 *
 * There is no tenant on this panel, so there is no tenant *database connection* — the
 * switch-tenant pipeline is what points `database.connections.tenant` at a company's
 * database, and it never runs here. A resource over a tenant model would fail on its
 * first query, or worse, read whatever connection was last configured.
 *
 * The trap this exists to catch: FiscalYear, EmailTemplate, CustomField and Comment are
 * tenant models whose resources live in the Core module, and Core is the module that is
 * always available. "Core is always available" is a statement about licensing and says
 * nothing about whether a company's database is attached — so registering one of them
 * here would look entirely reasonable.
 */
class PlatformPanelIsLandlordOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_super_admin' => true]));

        Filament::setCurrentPanel(Filament::getPanel('platform'));
        Filament::bootCurrentPanel();
    }

    /** @return array<int, class-string> */
    private function platformResources(): array
    {
        return array_values(Filament::getPanel('platform')->getResources());
    }

    public function test_this_test_has_something_to_check(): void
    {
        // A panel that failed to boot would make every assertion below vacuous.
        $this->assertNotEmpty($this->platformResources());
    }

    public function test_no_resource_on_the_platform_panel_has_a_tenant_model(): void
    {
        $offenders = [];

        foreach ($this->platformResources() as $resource) {
            $model = $resource::getModel();

            if (is_subclass_of($model, TenantModel::class)) {
                $offenders[] = "{$resource} → {$model}";
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These resources are registered on the platform panel over a model that lives in a',
            "company's database. There is no tenant here, so there is no tenant connection and",
            'the first query will fail. Either:',
            '  - move it back to the admin panel, where the whole request belongs to one company;',
            '  - or, if a platform admin genuinely needs it, link into /admin/{company} instead.',
            '',
            ...$offenders,
        ]));
    }

    /**
     * Guards the guard: the check above passes on an empty offender list, and would also
     * pass if the predicate never matched anything. These are the models it exists to
     * catch — tenant-backed, and living in Core, which is what makes the mistake plausible.
     *
     * Asserted by inheritance rather than by connection name, deliberately: under this
     * single-database suite a tenant model resolves to the default connection, so a check
     * on `getConnectionName()` would find nothing here and pass while proving nothing.
     */
    public function test_the_checks_above_would_actually_catch_a_tenant_model(): void
    {
        foreach ([
            \App\Modules\Core\Models\FiscalYear::class,
            \App\Modules\Core\Models\EmailTemplate::class,
            \App\Modules\Core\Models\CustomField::class,
            \App\Modules\Core\Models\Comment::class,
        ] as $model) {
            $this->assertTrue(
                is_subclass_of($model, TenantModel::class),
                "{$model} is what the landlord-only check is looking for"
            );
        }

        // And the landlord models on this panel are not caught by it.
        foreach ([\App\Modules\Core\Models\Company::class, User::class] as $model) {
            $this->assertFalse(is_subclass_of($model, TenantModel::class));
        }
    }

    public function test_the_panel_has_no_tenancy_and_so_no_company_is_current(): void
    {
        $this->assertFalse(Filament::getPanel('platform')->hasTenancy());
        $this->assertNull(\App\Modules\Core\Models\Company::current());
    }

    /**
     * Nothing may be registered on both panels. The same class on two panels would be
     * gated by one panel's rules and reached through the other's routes.
     */
    public function test_no_resource_is_registered_on_both_panels(): void
    {
        $shared = array_intersect(
            $this->platformResources(),
            array_values(Filament::getPanel('admin')->getResources()),
        );

        $this->assertSame([], array_values($shared));
    }

    /** Every platform class still belongs to a module, which is what gates and maps it. */
    public function test_every_platform_resource_is_in_the_module_map(): void
    {
        $mapped = array_values(ModuleMap::resources());

        foreach ($this->platformResources() as $resource) {
            $this->assertContains($resource, $mapped, "{$resource} is not in ModuleMap");
        }
    }

    /**
     * A platform class must not gate itself on a permission or a role.
     *
     * spatie's team id is null here — the listener that sets it runs on Filament's
     * TenantSet event, which never fires without tenancy — so `hasRole('Administrator')`
     * and `hasPermissionTo(…)` consult a team with no rows and answer no. A page gated
     * that way is invisible to the only people who can open this panel.
     */
    public function test_a_platform_admin_can_actually_see_every_platform_resource(): void
    {
        foreach ($this->platformResources() as $resource) {
            $this->assertTrue(
                $resource::canAccess(),
                "{$resource} is hidden from a platform admin — check it does not gate on a role or permission"
            );
        }
    }

    public function test_an_ordinary_user_can_see_none_of_them(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ($this->platformResources() as $resource) {
            $this->assertFalse($resource::canAccess(), "{$resource} is open to an ordinary user");
        }
    }
}
