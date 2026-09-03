<?php

namespace App\Support;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\OptionValue;
use Throwable;

/**
 * The dropdowns a company fills in for itself.
 *
 * A module declares its lists in its own `module.php`, next to the models and
 * permissions it declares, and ModuleManifest merges them:
 *
 *     'option_lists' => [
 *         'employees.designation' => [
 *             'label' => 'Designations',
 *             'help' => 'What people are called on the org chart.',
 *             'values' => ['Backend Developer', 'Cook'],          // value == label
 *         ],
 *         'employees.employment_type' => [
 *             'values' => ['permanent' => 'Permanent'],           // value => label
 *         ],
 *         // A dropdown edited on its own screen rather than here: a pointer, so the one
 *         // place an admin looks can still answer for it. See elsewhere().
 *         'petty_cash.category' => [
 *             'label' => 'Petty cash categories',
 *             'managed_by' => 'App\\Filament\\Resources\\TransactionTypes\\TransactionTypeResource',
 *         ],
 *     ],
 *
 * Declared by the module so the settings screen can hide the lists of a module this
 * company has not bought — the same rule the Leave section of Company Settings follows
 * — and so a module stays one directory and one file, which is the whole point of
 * docs/module-packaging-plan.md §5.
 *
 * **WHICH DROPDOWNS BELONG HERE.** Not the statuses, and this is the important half of
 * the design. Of the ~127 hardcoded option arrays in the panel, almost all are workflow
 * vocabulary: services branch on `ProgressClaim::STATUS_CERTIFIED`, reports key off
 * `Lead::STATUS_CONVERTED`, and most of those columns are `enum(...)` in the migration, so a
 * value added by an admin would be silently ignored by the code and rejected by the
 * database. A list belongs here when all three hold:
 *
 *  - the column is a plain `string`, not an `enum`;
 *  - nothing in `app/` compares the value to a constant;
 *  - two companies would genuinely write different lists.
 *
 * Reads fall back to the declared defaults when the table has no rows — an unprovisioned
 * tenant, a test that seeded nothing — for the same reason TenantSettings falls back to
 * config: a dropdown with nothing in it is worse than one that is merely not yet edited.
 */
class OptionLists
{
    /** @var array<int|string, array<string, array<string, string>>> */
    private static array $cache = [];

    /**
     * Every declared list: key => module, label, help, defaults.
     *
     * @return array<string, array{module: string, label: string, help: string|null, defaults: array<string, string>}>
     */
    public static function all(): array
    {
        $lists = [];

        foreach (static::declared() as $key => [$module, $definition]) {
            if (isset($definition['managed_by'])) {
                continue;
            }

            $lists[$key] = [
                'module' => $module,
                'label' => static::labelFor($key, $definition),
                'help' => $definition['help'] ?? null,
                'defaults' => static::normalise($definition['values'] ?? []),
            ];
        }

        return $lists;
    }

    /**
     * The dropdowns that are edited somewhere else, and where.
     *
     * A company's petty cash categories are its transaction types; its leave types are rows with their own
     * screen. Those were always editable and the complaint was never that they were not — it was that the
     * one place an admin looks did not account for them. So a module points at the screen instead of
     * declaring values, and this resolves the pointer to something clickable.
     *
     * Judged by the resource's own `canAccess()`, which folds in both the module licence and the
     * permission: a pointer to a screen this person cannot open is worse than no pointer.
     *
     * @return array<string, array{label: string, help: string|null, url: string, resource: string}>
     */
    public static function elsewhere(): array
    {
        $pointers = [];

        foreach (static::declared() as $key => [$module, $definition]) {
            $alias = $definition['managed_by'] ?? null;

            if ($alias === null) {
                continue;
            }

            $resource = static::resourceFor($alias);

            if ($resource === null) {
                continue;
            }

            try {
                if (! $resource::canAccess()) {
                    continue;
                }

                $url = $resource::getUrl('index');
            } catch (Throwable) {
                // No panel, no tenant, or a resource without an index route. A pointer we
                // cannot resolve is left out rather than rendered as a dead link.
                continue;
            }

            $pointers[$key] = [
                'label' => static::labelFor($key, $definition),
                'help' => $definition['help'] ?? null,
                'url' => $url,
                'resource' => $resource,
            ];
        }

        return $pointers;
    }

    /**
     * Every declared entry, whichever shape it takes: key => [module, definition].
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    private static function declared(): array
    {
        $declared = [];

        foreach (ModuleManifest::all()['option_lists'] ?? [] as $module => $lists) {
            foreach ($lists as $key => $definition) {
                $declared[$key] = [$module, $definition];
            }
        }

        return $declared;
    }

    /** The class behind a manifest resource alias, or null when no module declares it. */
    private static function resourceFor(string $alias): ?string
    {
        foreach (ModuleManifest::all()['resources'] ?? [] as $entries) {
            if (isset($entries[$alias])) {
                return $entries[$alias];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $definition */
    private static function labelFor(string $key, array $definition): string
    {
        return $definition['label'] ?? str($key)->afterLast('.')->headline()->toString();
    }

    /**
     * The lists this company may see: the module owning them is available to it.
     *
     * @return array<string, array{module: string, label: string, help: string|null, defaults: array<string, string>}>
     */
    public static function enabled(): array
    {
        return array_filter(static::all(), fn (array $list): bool => modules()->enabled($list['module']));
    }

    /**
     * What one dropdown offers, as value => label.
     *
     * `$keep` is a value already stored on the record being edited. A list is editable,
     * so a value can be switched off or deleted after rows were saved with it — and a
     * Select whose current value is not among its options renders blank and writes that
     * blank back on the next save. Passing the record's own value keeps it visible and
     * unchanged until somebody deliberately picks something else.
     *
     * @return array<string, string>
     */
    public static function get(string $list, ?string $keep = null): array
    {
        $options = static::stored()[$list] ?? static::all()[$list]['defaults'] ?? [];

        if ($keep !== null && $keep !== '' && ! array_key_exists($keep, $options)) {
            $options = [$keep => $keep] + $options;
        }

        return $options;
    }

    public static function flush(): void
    {
        static::$cache = [];
    }

    /**
     * Every list that has rows, for the current tenant, read once per request.
     *
     * @return array<string, array<string, string>>
     */
    private static function stored(): array
    {
        $tenant = Company::current()?->getKey() ?? 'default';

        if (array_key_exists($tenant, static::$cache)) {
            return static::$cache[$tenant];
        }

        try {
            $rows = OptionValue::query()
                ->active()
                ->orderBy('sort')
                ->orderBy('label')
                ->get(['list', 'value', 'label']);
        } catch (Throwable) {
            // No table yet (tenant not provisioned). The declared defaults answer.
            return static::$cache[$tenant] = [];
        }

        return static::$cache[$tenant] = $rows
            ->groupBy('list')
            ->map(fn ($group): array => $group->pluck('label', 'value')->all())
            ->all();
    }

    /**
     * A declared list may be written as labels or as value => label; both mean the same
     * thing when the stored value is the label itself.
     *
     * @param  array<int|string, string>  $values
     * @return array<string, string>
     */
    private static function normalise(array $values): array
    {
        return array_is_list($values) ? array_combine($values, $values) : $values;
    }
}
