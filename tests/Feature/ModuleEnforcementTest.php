<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Multitenancy\Tasks\SetPermissionsTeamIdTask;
use App\Multitenancy\Tasks\SwitchTenantFilesystemTask;
use App\Support\ModuleAuthorization;
use App\Support\ModuleManifest;
use App\Support\TenantSettings;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\MakesProjects;
use Tests\TestCase;

/**
 * Phase 2: the point at which a disabled module becomes unreachable rather than
 * merely hidden. Everything here is a negative assertion about a surface that
 * bypasses canAccess() — a typed URL, an API call, a scheduled command, or an
 * authorization check made by a super admin.
 */
class ModuleEnforcementTest extends TestCase
{
    use MakesProjects;
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Single-database suite: drop the DB-switch task, as StatusPageTest does.
        config(['multitenancy.switch_tenant_tasks' => [
            SetPermissionsTeamIdTask::class,
            SwitchTenantFilesystemTask::class,
        ]]);

        $this->seed(PermissionSeeder::class);

        // `ModuleAuthorization::flush()` used to be needed here, because the permission
        // name => group map was read from the database and memoised — so a check made
        // before this seeder ran cached an empty map for the process and stopped blocking
        // anything. The map now comes from the module manifests, so there is nothing for a
        // seeder to invalidate, and its absence here is part of what proves that.

        $this->company = Company::factory()->create(['slug' => 'acme']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        (new RoleSeeder)->run();

        $this->user = User::factory()->create();
        $this->company->users()->attach($this->user->getKey());
        $this->user->assignRole('Administrator');

        Cache::flush();
    }

    private function setModule(string $module, bool $on, ?bool $licensed = null): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->company->getKey(), 'module' => $module],
            ['licensed' => $licensed ?? $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    /** The company is in the path since these pages resolve their own tenant. */
    private function reportUrl(string $name): string
    {
        return route($name, ['company' => $this->company->slug]);
    }

    public function test_report_urls_are_closed_when_accounting_is_off(): void
    {
        // The whole point of phase 2: these routes never consult canAccess(), so
        // before this they answered for any authenticated user with ReportView.
        $this->actingAs($this->user);

        $this->setModule('accounting', true);
        $this->get($this->reportUrl('reports.trial-balance'))->assertOk();

        $this->setModule('accounting', false);
        $this->get($this->reportUrl('reports.trial-balance'))->assertForbidden();
        $this->get($this->reportUrl('reports.profit-and-loss'))->assertForbidden();
    }

    public function test_a_licensed_but_switched_off_module_closes_its_urls_too(): void
    {
        $this->actingAs($this->user);

        $this->setModule('accounting', false, licensed: true);

        $this->get($this->reportUrl('reports.trial-balance'))->assertForbidden();
    }

    public function test_api_endpoints_are_closed_per_module(): void
    {
        // The user's decision: if the module is not enabled the client cannot
        // reach it at all, rather than receiving an empty payload.
        Employee::create([
            'user_id' => $this->user->getKey(),
            'employee_id' => 'E-1',
            'gender' => 'Male',
            'phone' => 'ph-1',
        ]);

        $this->actingAs($this->user, 'sanctum');

        $this->setModule('payroll', true);
        $this->setModule('mpr', false);

        $this->getJson('/api/my-payslips')->assertOk();
        $this->getJson('/api/my-mprs')->assertForbidden();

        $this->setModule('payroll', false);
        $this->getJson('/api/my-payslips')->assertForbidden();
    }

    public function test_the_accounting_api_group_is_closed_as_a_whole(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $this->setModule('accounting', false);

        $this->getJson('/api/accounts/tree')->assertForbidden();
        $this->getJson('/api/accounts')->assertForbidden();
        $this->getJson('/api/reports/trial-balance')->assertForbidden();
    }

    public function test_the_public_status_page_needs_the_projects_module_as_well_as_its_own_switch(): void
    {
        $settings = app(TenantSettings::class);
        $settings->set('projects.status_page.enabled', true);
        $settings->set('projects.status_page.token', $token = 'test-status-token-1234567890');

        $this->makeEnvironment($this->makeProject(), ['is_public' => true]);

        $url = '/status/'.$this->company->slug.'/'.$token;

        $this->setModule('projects', true);
        $this->get($url)->assertOk();

        // 404, not 403: an unlisted page should not confirm it exists, matching
        // how the token and the enabled flag already behave.
        $this->setModule('projects', false);
        $this->get($url)->assertNotFound();
    }

    public function test_authorization_is_denied_for_a_disabled_module(): void
    {
        $this->actingAs($this->user);

        $this->setModule('accounting', true);
        $this->assertTrue(Gate::allows('ReportView'));

        $this->setModule('accounting', false);
        $this->assertFalse(Gate::allows('ReportView'), 'A permission owned by a disabled module must not authorize.');
    }

    public function test_a_super_admin_does_not_bypass_a_disabled_module(): void
    {
        // The deliberate decision: a module the company has not bought is not a
        // permission question. This is also the regression guard for the ordering
        // inside Gate::before — the super-admin bypass returns true for
        // everything, so a check placed after it would never run.
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->company->users()->attach($superAdmin->getKey());
        $this->actingAs($superAdmin);

        $this->setModule('accounting', false);

        $this->assertFalse(Gate::allows('ReportView'));
        $this->assertFalse(Gate::allows('view', new \App\Modules\Accounting\Models\Account));
        $this->get($this->reportUrl('reports.trial-balance'))->assertForbidden();
    }

    public function test_an_administrator_does_not_bypass_a_disabled_module(): void
    {
        // Same ordering problem: hasRole('Administrator') returns true for every
        // non-create ability.
        $this->actingAs($this->user);

        $this->setModule('invoicing', false);

        $this->assertFalse(Gate::allows('view', new \App\Modules\Invoicing\Models\Invoice));
    }

    /**
     * **The hole the model-argument test above does not cover: a bare permission name.**
     *
     * `test_an_administrator_does_not_bypass_a_disabled_module` passes a model, which resolves through
     * `ModuleMap` and never needed a database. A string permission — `ProductView`, `ReportView`, the
     * form every report page and export action uses — resolves through the permission's *group*, and
     * that is the path that was reachable: with the group unresolved, nothing is blocked, and the
     * Administrator bypass in `AppServiceProvider::boot()` then grants it.
     */
    public function test_an_administrator_does_not_bypass_a_disabled_module_on_a_bare_permission_name(): void
    {
        $this->actingAs($this->user);

        $this->setModule('inventory', true);
        $this->assertTrue(Gate::allows('ProductView'), 'the permission is held while the module is on');

        $this->setModule('inventory', false);

        $this->assertFalse(
            Gate::allows('ProductView'),
            'A string permission owned by an unlicensed module must not authorize, Administrator or not.'
        );
    }

    /**
     * **The licence check does not depend on the permissions table being readable.**
     *
     * This is the bypass as it would have happened in production rather than in a test ordering. The map
     * was `DB::table('permissions')` behind a `try/catch` that cached `[]` on failure and never retried,
     * so one moment of the landlord table being unreachable — mid-migration, a worker booting before its
     * connection is pointed at it — left the map permanently empty for the life of the process. Empty map,
     * no group, no candidate module, nothing blocked, and every string permission of every unbought module
     * granted. Silent, permanent, and fail-open.
     *
     * So: drop the table, flush the memo to simulate a cold process, and the deny must still fire. It
     * does, because the map is built from the manifests — which are code, and cannot be half-there.
     */
    public function test_the_licence_deny_survives_the_permissions_table_being_unreadable(): void
    {
        $this->actingAs($this->user);
        $this->setModule('inventory', false);

        // A cold process, as if this were the very first authorization check it ever made.
        ModuleAuthorization::flush();
        Schema::drop('permissions');

        $this->assertSame(
            'inventory',
            ModuleAuthorization::blockingModule($this->user, 'ProductView', []),
            'the group resolves from the manifests, with no table to read'
        );
        $this->assertFalse(Gate::allows('ProductView'));
    }

    /**
     * And the map is the manifests', not the table's — asserted by comparing the two.
     *
     * `PermissionSeeder` writes the table **from** `ModuleManifest::all()['permissions']`, so the column
     * this used to read is a copy of what it now reads directly. If the two ever disagreed, the seeder and
     * the licence gate would be answering from different lists, which is the drift this test exists to
     * catch.
     */
    public function test_every_seeded_permission_group_matches_the_manifest(): void
    {
        $fromTable = DB::table('permissions')->whereNotNull('group')->pluck('group', 'name')->all();

        $fromManifests = [];

        foreach (ModuleManifest::all()['permissions'] ?? [] as $permission) {
            $fromManifests[$permission['name']] = $permission['group'];
        }

        $this->assertNotEmpty($fromManifests, 'the manifests declare permissions');
        $this->assertSame(
            $fromManifests,
            $fromTable,
            'The seeded table and the manifests must agree — the licence gate reads the manifests.'
        );
    }

    public function test_core_authorization_survives_every_module_being_off(): void
    {
        $this->actingAs($this->user);

        foreach (['accounting', 'invoicing', 'inventory', 'payroll', 'employees', 'projects', 'mpr'] as $module) {
            $this->setModule($module, false);
        }

        $this->assertTrue(Gate::allows('viewAnyRole'), 'A company must keep administering itself.');
        $this->assertTrue(Gate::allows('view', $this->user));
    }

    public function test_scheduled_project_checks_skip_a_company_with_projects_off(): void
    {
        // No UI hides this one: without the guard, a company with Projects off has
        // its environments polled every minute and rows written to its database.
        $this->company->makeCurrent();

        try {
            $this->setModule('projects', false);

            $this->artisan('projects:check-health', ['--tenant' => [$this->company->getKey()]])
                ->expectsOutputToContain('is not enabled')
                ->assertSuccessful();

            $this->artisan('projects:check-certificates', ['--tenant' => [$this->company->getKey()]])
                ->expectsOutputToContain('is not enabled')
                ->assertSuccessful();
        } finally {
            Company::forgetCurrent();
        }
    }

    public function test_scheduled_project_checks_run_when_projects_is_on(): void
    {
        $this->company->makeCurrent();

        try {
            $this->setModule('projects', true);

            $this->artisan('projects:check-health', ['--tenant' => [$this->company->getKey()]])
                ->expectsOutputToContain('Dispatched')
                ->assertSuccessful();
        } finally {
            Company::forgetCurrent();
        }
    }
}
