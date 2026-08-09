<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 * `group` column, so the name => group map is loaded once per request from the
 * landlord permissions table; without it, every string-permission check would
 * pass straight through and a licensed-off module would still authorize its
 * actions.
 */
final class ModuleAuthorization
{
    /** @var array<string, string>|null */
    private static ?array $permissionGroups = null;

    /**
     * The module that should block this check, or null to let authorization
     * proceed normally.
     *
     * @param  array<int, mixed>  $arguments
     */
    public static function blockingModule(?Authenticatable $user, string $ability, array $arguments): ?string
    {
        foreach (static::candidateModules($ability, $arguments) as $module) {
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

        $group = static::groupOf($ability);

        if ($group !== null && ($module = ModuleMap::moduleForPermissionGroup($group)) !== null) {
            $modules[] = $module;
        }

        return array_values(array_unique($modules));
    }

    private static function groupOf(string $ability): ?string
    {
        return static::permissionGroups()[$ability] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function permissionGroups(): array
    {
        if (static::$permissionGroups !== null) {
            return static::$permissionGroups;
        }

        try {
            static::$permissionGroups = DB::table('permissions')
                ->whereNotNull('group')
                ->pluck('group', 'name')
                ->all();
        } catch (Throwable) {
            // Permissions table unavailable (mid-migration, or a test that does
            // not seed it). Model-argument abilities still resolve.
            static::$permissionGroups = [];
        }

        return static::$permissionGroups;
    }

    /**
     * Tests and long-running workers seed permissions after the first check.
     */
    public static function flush(): void
    {
        static::$permissionGroups = null;
    }
}
