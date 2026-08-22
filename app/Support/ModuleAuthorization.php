<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the module behind an authorization check, so Gate::before can deny it
 * outright when the company has not licensed that module.
 *
 * Two shapes of ability reach the gate here:
 *
 *  - a policy ability with a model argument — `$user->can('view', $account)`,
 *    which is what Filament resource authorization does;
 *  - a bare permission name — `$user->can('ReportView')`, used by the report
 *    pages, the export actions and several widgets.
 *
 * The first resolves through ModuleMap. The second needs the permission's
 * **group**, and that comes from the module manifests rather than from the
 * `permissions` table — which matters, because "without it, every
 * string-permission check would pass straight through and a licensed-off module
 * would still authorize its actions".
 *
 * **It used to read `DB::table('permissions')`, memoised for the process, and
 * that was a licence bypass waiting to happen.** The query sat behind a
 * `try/catch` that cached `[]` on failure and never retried, so any single
 * moment where the landlord table was unreachable — mid-migration, a worker
 * booting against a connection that has not been pointed at it yet, a test
 * whose first check precedes its seeder — left the map permanently empty for
 * the life of the process. An empty map means `groupOf()` returns null, no
 * candidate module is found, nothing is blocked, and the Administrator bypass
 * in AppServiceProvider::boot() then grants every string permission of every
 * module the company never bought. Silent, permanent, and fail-open.
 *
 * The manifests are the fix and they are also the *authority*: `PermissionSeeder`
 * writes the `permissions` table **from** `ModuleManifest::all()['permissions']`,
 * so the column this class used to read is a copy of what it can read directly.
 * Manifests are code, cached in `bootstrap/cache/mizan-modules.php` — they cannot
 * be briefly unavailable, which removes both the query on every authorization
 * check and the window that made the bypass reachable.
 */
final class ModuleAuthorization
{
    /**
     * Permission name => group, from the manifests.
     *
     * Memoised because `blockingModule()` runs inside a `Gate::before` — that is
     * every authorization check in the application — and this is a fold over a
     * few hundred manifest rows. Safe to memoise in a way the old database read
     * was not: `ModuleManifest::all()` is already cached and cannot fail
     * transiently, so there is no empty-and-stuck state to fall into.
     *
     * @var array<string, string>|null
     */
    private static ?array $permissionGroups = null;

    /**
     * The module that should block this check, or null to let authorization
     * proceed normally.
     *
     * @param  array<int, mixed>  $arguments
     */
    public static function blockingModule(?Authenticatable $user, string $ability, array $arguments): ?string
    {
        foreach (self::candidateModules($ability, $arguments) as $module) {
            // The user comes from Gate::before rather than auth(): the gate may be
            // asked about a user who is not the logged-in one, and in a queued job
            // there is no logged-in one at all.
            if (! modules()->availableTo($user, $module)) {
                return $module;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $arguments
     * @return array<int, string>
     */
    private static function candidateModules(string $ability, array $arguments): array
    {
        $modules = [];

        foreach ($arguments as $argument) {
            $class = match (true) {
                $argument instanceof Model => $argument::class,
                is_string($argument) && class_exists($argument) => $argument,
                default => null,
            };

            if ($class !== null && ($module = ModuleMap::moduleFor($class)) !== null) {
                $modules[] = $module;
            }
        }

        $group = self::groupOf($ability);

        if ($group !== null && ($module = ModuleMap::moduleForPermissionGroup($group)) !== null) {
            $modules[] = $module;
        }

        return array_values(array_unique($modules));
    }

    private static function groupOf(string $ability): ?string
    {
        return self::permissionGroups()[$ability] ?? null;
    }

    /**
     * The name => group map, built from what the modules declare.
     *
     * **No database, deliberately** — see the class docblock. A permission that
     * exists in the table and in no manifest resolves to no group and is not
     * blocked, which is the same answer the old code gave for such a row and is
     * the correct one: `ModuleCoverageTest` already fails the build for a
     * permission group no module claims, so an unclaimed name is a broken build
     * rather than a runtime case to guess at.
     *
     * @return array<string, string>
     */
    private static function permissionGroups(): array
    {
        if (self::$permissionGroups !== null) {
            return self::$permissionGroups;
        }

        $groups = [];

        foreach (ModuleManifest::all()['permissions'] ?? [] as $permission) {
            $name = $permission['name'] ?? null;
            $group = $permission['group'] ?? null;

            if (is_string($name) && is_string($group) && $group !== '') {
                $groups[$name] = $group;
            }
        }

        return self::$permissionGroups = $groups;
    }

    /**
     * Drop the memoised map.
     *
     * Kept for the one caller that still needs it — a test that swaps the
     * manifests under the application. It is no longer load-bearing for
     * *correctness*: the map is built from code rather than from a table that
     * might not be seeded yet, so a stale-empty map is no longer reachable.
     */
    public static function flush(): void
    {
        self::$permissionGroups = null;
    }
}
