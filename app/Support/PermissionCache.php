<?php

namespace App\Support;

use App\Modules\Core\Models\Company;
use Illuminate\Support\Facades\Cache;
use Spatie\Multitenancy\Tasks\PrefixCacheTask;
use Spatie\Permission\PermissionRegistrar;

/**
 * Invalidates spatie's permission cache in every company, not just the current one.
 *
 * The permissions themselves are installation-wide — one landlord `permissions` table — but
 * the cache of them is not. PrefixCacheTask sets `cache.prefix` to `tenant_id_{id}` while a
 * company is current, so each company reads its own copy of the list, and
 * `forgetCachedPermissions()` only ever clears the copy belonging to whatever context happens
 * to be active.
 *
 * Every path that adds a permission runs outside a company: the seeder, and the Permissions
 * screen, which lives on the platform panel precisely because permissions are not a company's
 * to decide. So the copy that gets invalidated is the one nobody reads, and every company
 * keeps serving a stale list until its own 24-hour TTL runs out.
 *
 * What that looks like from the outside is not a stale menu. `hasPermissionTo()` throws
 * PermissionDoesNotExist for a name it cannot find, policies call it while Filament builds the
 * sidebar, and the whole panel returns a 500 — "There is no permission named `AdvanceView` for
 * guard `web`" for a permission that plainly exists in the table. That is how this was found:
 * one company on 120 cached permissions while the table held 135.
 */
class PermissionCache
{
    /**
     * Forget the cached permission list for the current context and for every company.
     *
     * Works on the cache prefix rather than by making each company current: the key is a
     * landlord-side cache entry, so there is no reason to switch database connections or
     * spatie's team id to reach it, and doing so had side effects on the caller.
     *
     * PrefixCacheTask does the prefixing, reused rather than reimplemented because getting the
     * cache to notice a new prefix takes four container purges and a facade reset — and
     * missing one is silent. Two earlier attempts here failed exactly that way: the registrar
     * is a singleton holding both its cache repository and the manager it came from, so it
     * kept forgetting the first company's key while reporting success for all of them.
     *
     * @return array<int, string> the companies flushed, for a caller that wants to report it
     */
    public static function flushEverywhere(): array
    {
        $key = config('permission.cache.key', 'spatie.permission.cache');

        // The no-tenant copy: the platform panel and the console read this one.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Without a dedicated tenant connection — the test suite — nothing prefixes the cache,
        // so the copy just forgotten is the only one there is.
        if (! config('multitenancy.tenant_database_connection_name')) {
            return [];
        }

        // Constructed here, while the original prefix is in force, so forgetCurrent() below
        // restores that and not some company's.
        $prefixer = new PrefixCacheTask;
        $flushed = [];

        foreach (Company::orderBy('id')->get() as $company) {
            $prefixer->makeCurrent($company);

            Cache::forget($key);

            $flushed[] = $company->slug ?? (string) $company->getKey();
        }

        $prefixer->forgetCurrent();

        // The registrar is still holding a cache repository built under one of the prefixes
        // above; rebuild it so the caller's next permission check reads its own context.
        //
        // Its team id has to be carried across by hand, though. That id is request state
        // living on the singleton, so discarding the instance silently reset it to null —
        // and a null team is not "no company", it is "every role belongs to nobody". What
        // that broke was the caller *after* this one: PermissionSeeder ends here, RoleSeeder
        // runs next and reads the team from the registrar, so it seeded roles for no company
        // and `assignRole('Administrator')` then threw RoleDoesNotExist. Eight tests, and a
        // message about a role that names nothing to do with the cache.
        $teamId = app(PermissionRegistrar::class)->getPermissionsTeamId();

        app()->forgetInstance(PermissionRegistrar::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($teamId);

        return $flushed;
    }
}
