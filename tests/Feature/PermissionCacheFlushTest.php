<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Support\PermissionCache;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A permission the table has and the cache does not.
 *
 * Reported as "There is no permission named `AdvanceView` for guard `web`" on the dashboard of
 * a company whose `permissions` table plainly contained it — one company was serving a cached
 * list of 120 while the table held 135. `hasPermissionTo()` throws for a name it cannot
 * resolve, every policy calls it, and Filament calls every policy to build the sidebar, so a
 * stale cache is not a missing menu item: it is a 500 on every page of the panel.
 *
 * The cache is per company (PrefixCacheTask prefixes it `tenant_id_{id}`) while the
 * permissions are installation-wide, and everything that adds one — the seeder, the platform
 * Permissions screen — runs with no company current, so spatie invalidates the one copy nobody
 * reads. See PermissionCache.
 *
 * There is no tenant connection in this suite, so the per-company prefixing cannot be
 * exercised here; what is asserted is the trap itself and that flushing clears it.
 */
class PermissionCacheFlushTest extends TestCase
{
    use RefreshDatabase;

    private function registrar(): PermissionRegistrar
    {
        return app(PermissionRegistrar::class);
    }

    private function cacheKey(): string
    {
        return config('permission.cache.key');
    }

    /**
     * Asked of the cache store, not the `cache` table: this suite runs on the array driver
     * (CACHE_STORE=array in phpunit.xml), so nothing is ever written to that table and a
     * query against it reads false whether the list is cached or not.
     */
    private function isCached(): bool
    {
        return Cache::has($this->cacheKey());
    }

    public function test_the_command_is_registered(): void
    {
        $this->assertArrayHasKey('permission:flush-cache', Artisan::all());
    }

    /**
     * The failure mode, reproduced: a permission inserted behind the cache's back — which is
     * what a seeder in another context amounts to — is invisible and throws.
     */
    public function test_a_permission_missing_from_a_stale_cache_throws_rather_than_denying(): void
    {
        $this->seed(PermissionSeeder::class);

        $user = User::factory()->create();

        // Prime the cache with today's list.
        $this->registrar()->getPermissions();

        // Inserted through the query builder on purpose: no model event, so spatie never hears
        // about it and the cached list stays as it was.
        DB::table('permissions')->insert([
            'name' => 'WidgetView',
            'group' => 'Widget',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue(
            Permission::where('name', 'WidgetView')->exists(),
            'the row is really there — which is what made the error so confusing'
        );

        try {
            $user->hasPermissionTo('WidgetView');
            $this->fail('expected spatie to throw for a permission it has not cached');
        } catch (PermissionDoesNotExist $e) {
            $this->assertStringContainsString('WidgetView', $e->getMessage());
        }

        PermissionCache::flushEverywhere();

        // Resolves now, and answers false rather than exploding: the user simply does not hold it.
        $this->assertFalse($user->hasPermissionTo('WidgetView'));
    }

    public function test_flushing_forgets_the_cached_list(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->registrar()->getPermissions();
        $this->assertTrue($this->isCached(), 'the list is cached to begin with');

        PermissionCache::flushEverywhere();

        $this->assertFalse($this->isCached());
    }

    /**
     * The flush rebuilds the registrar, and its team id is request state living on that
     * singleton — so discarding the instance reset it to null, and a null team is not "no
     * company", it is "every role belongs to nobody".
     *
     * What that broke was the caller after this one. PermissionSeeder ends with a flush,
     * RoleSeeder runs next and reads the team from the registrar, so it seeded roles for no
     * company at all and the next assignRole() threw RoleDoesNotExist — eight tests failing
     * with a message about a role, naming nothing to do with a cache.
     */
    public function test_the_company_in_context_survives_a_flush(): void
    {
        $company = Company::factory()->create();

        $this->registrar()->setPermissionsTeamId($company->getKey());

        PermissionCache::flushEverywhere();

        $this->assertSame($company->getKey(), $this->registrar()->getPermissionsTeamId());
    }

    public function test_no_company_in_context_stays_no_company(): void
    {
        $this->registrar()->setPermissionsTeamId(null);

        PermissionCache::flushEverywhere();

        $this->assertNull($this->registrar()->getPermissionsTeamId());
    }

    /** The sequence that failed: seed permissions, then roles, then use one. */
    public function test_roles_seeded_after_a_flush_belong_to_the_company(): void
    {
        $company = Company::factory()->create();
        $this->registrar()->setPermissionsTeamId($company->getKey());

        $this->seed(PermissionSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->assertSame(5, \Spatie\Permission\Models\Role::where('company_id', $company->getKey())->count());
        $this->assertSame(0, \Spatie\Permission\Models\Role::whereNull('company_id')->count());
    }

    public function test_it_reports_no_companies_without_a_tenant_connection(): void
    {
        // The guard that keeps this a no-op in a single-database installation: with no prefixing
        // there is one copy, and walking the companies would switch spatie's team id for nothing.
        config(['multitenancy.tenant_database_connection_name' => null]);

        $this->assertSame([], PermissionCache::flushEverywhere());
    }

    public function test_seeding_permissions_leaves_no_stale_cache_behind(): void
    {
        $this->registrar()->getPermissions();
        $this->assertTrue($this->isCached());

        $this->seed(PermissionSeeder::class);

        // The seeder flushes as its last act, so nothing is left holding the pre-seed list.
        $this->assertFalse($this->isCached(), 'PermissionSeeder must not leave a stale cache');
    }

    /** Every permission a policy names has to exist, or that policy is a 500 waiting to happen. */
    public function test_every_permission_the_policies_check_exists_in_the_seeder(): void
    {
        $this->seed(PermissionSeeder::class);

        $known = Permission::pluck('name')->all();
        $missing = [];

        foreach (glob(base_path('app/Modules/*/Policies/*.php')) as $file) {
            preg_match_all(
                '/(?:hasPermissionTo|checkPermissionTo|can)\(\s*[\'"]([A-Za-z]+)[\'"]/',
                file_get_contents($file),
                $matches,
            );

            foreach ($matches[1] as $permission) {
                if (! in_array($permission, $known, true)) {
                    $missing[] = basename($file).' → '.$permission;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), implode("\n", [
            'A policy checks a permission that PermissionSeeder does not create. Any user',
            'without super-admin rights gets PermissionDoesNotExist — a 500, not a denial:',
            '',
            ...array_unique($missing),
        ]));
    }
}
