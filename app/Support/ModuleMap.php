<?php

namespace App\Support;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Which class belongs to which module.
 *
 * Read through this class, declared in each module's own `module.php`, and merged
 * by ModuleManifest. It was five central `const` arrays; the reasons for keeping
 * the *lookup* central still hold, and none of them required the *data* to be:
 *
 *  - the Modules page needs the whole mapping at once (record counts, what a
 *    module contains, which permission groups it owns);
 *  - a resource declared by nobody fails ModuleCoverageTest rather than shipping
 *    ungated, which a forgotten `$module` property would not guarantee;
 *  - the morph map is built from the same declarations, so a moved model cannot
 *    be forgotten.
 *
 * What moving the data bought: this class no longer names a single module, so
 * shared code no longer depends on all 22 of them — see the SHARED_DEBT entry it
 * used to require in ModuleBoundaryTest.
 *
 * MORPH MAP: the aliases in morphMap() are the *legacy* `App\Models\…` strings,
 * because those are what is already stored in customer data — `comments.commentable_type`,
 * `payments.payable_type`, `activity_log.subject_type`, `custom_fields.model_type`,
 * `model_has_roles.model_type` and `table_views.resource`. Keeping the alias
 * fixed while the target class moves means existing rows keep resolving and new
 * rows are written identically, with no per-tenant data migration. Ugly strings,
 * correct behaviour.
 */
final class ModuleMap
{
    /**
     * One of the five ownership tables, keyed by module.
     *
     * These were five hand-written `private const` arrays — 279 entries that every
     * new module had to append to, and 222 inline class references that made this
     * "shared" class depend on all 22 modules at once. They now come from each
     * module's own `module.php`, merged by ModuleManifest, so this class names
     * nobody. See docs/module-packaging-plan.md §5.
     *
     * @return array<string, array<string, string>>
     */
    private static function table(string $name): array
    {
        return ModuleManifest::all()[$name] ?? [];
    }

    /**
     * Legacy FQCN => current class, for Relation::enforceMorphMap().
     *
     * @return array<string, class-string>
     */
    public static function morphMap(): array
    {
        $models = array_values(self::table('models'));

        // enforceMorphMap() is fed from here, and an empty map would mean every
        // model throwing on its first polymorphic use. Discovery finding nothing
        // is a broken install, not a company that owns no models.
        return $models === [] ? [] : array_merge(...$models);
    }

    /**
     * The stable string to store for a class, rather than the class itself.
     *
     * Anywhere a class name is written into a column, this is what goes in:
     * `journal_entries.source_type`, `stock_movements.source_type`,
     * `payments.payable_type`, `custom_fields.model_type`, `table_views.resource`.
     *
     * Those are plain column writes, not morph relations, so enforceMorphMap()
     * does not cover them — `where('source_type', \App\Modules\Payroll\Models\Payslip::class)` would simply
     * stop matching the day Payslip moves into app/Modules/Payroll, and
     * unwindForPayslip would quietly find no entries to reverse. Storing the
     * alias instead keeps every existing row valid across the move.
     *
     * A class that *should* have an alias and does not now throws, rather than
     * returning unchanged.
     *
     * The pass-through was the quiet half of the guarantee above. It made this
     * safe to wrap around anything — and it also meant that a model which moved
     * without its map entry being updated wrote its **new** FQCN into the column
     * and reported success. Reads then match nothing: `unwindForPayslip` finds no
     * entries to reverse, a comments panel shows an empty thread, a saved view
     * resolves to no resource. Nothing throws, nothing logs, and the rows are
     * already wrong.
     *
     * `docs/modules-plan.md` §4 is explicit that enforcing the map *before*
     * moving anything is what made the last structural change survive, and
     * docs/module-packaging-plan.md §4 needs the same rail before its later
     * phases move classes between modules.
     *
     * Genuinely foreign classes still pass through: the guarantee is only owed
     * for things this application owns and stores by name.
     */
    public static function alias(string $class): string
    {
        foreach ([self::table('models'), self::table('resources'), self::table('pages'), self::table('widgets'), self::table('datasets')] as $table) {
            foreach ($table as $classes) {
                $alias = array_search($class, $classes, true);

                if ($alias !== false) {
                    return $alias;
                }
            }
        }

        if (self::owesAnAlias($class)) {
            throw new RuntimeException(sprintf(
                '%s is not declared by any module, so writing it to a class-string column '
                .'would store an FQCN that stops matching the day the class moves. Add it to '
                .'the owning module.php under models, resources, pages or widgets.',
                $class,
            ));
        }

        return $class;
    }

    /**
     * Whether a class is one of the kinds this application stores by name and
     * therefore must have a stable alias for.
     *
     * Judged by what the class *is*, not by where it sits: a tenant model, or a
     * Filament resource, page or widget. Anything unloadable is left alone —
     * `alias()` is called with strings read back out of columns, and a class that
     * no longer exists must not turn a read into a fatal.
     */
    private static function owesAnAlias(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        foreach ([
            \App\Models\TenantModel::class,
            \Filament\Resources\Resource::class,
            \Filament\Pages\Page::class,
            \Filament\Widgets\Widget::class,
            // A dataset's key is stored in every report definition built over it — Phase 6, item 3 — so it
            // owes a stable alias for exactly the reason a model does.
            \App\Support\Reporting\Dataset::class,
        ] as $base) {
            if (is_subclass_of($class, $base)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The module a class belongs to, or null when it is not mapped.
     *
     * Namespace derivation comes first so that once a class lives in
     * app/Modules/Payroll/… it is gated by where it sits, and the explicit
     * tables below are only needed until phase 5 has moved it.
     */
    public static function moduleFor(string $class): ?string
    {
        if (preg_match('/^App\\\\Modules\\\\([A-Za-z0-9]+)\\\\/', $class, $matches)) {
            $module = Str::snake($matches[1]);

            return array_key_exists($module, config('modules', [])) ? $module : null;
        }

        foreach ([self::table('resources'), self::table('pages'), self::table('widgets'), self::table('datasets')] as $table) {
            foreach ($table as $module => $classes) {
                if (in_array($class, $classes, true)) {
                    return $module;
                }
            }
        }

        foreach (self::table('models') as $module => $models) {
            if (in_array($class, $models, true)) {
                return $module;
            }
        }

        return null;
    }

    /** @return array<int, class-string> */
    public static function resources(?string $module = null): array
    {
        return self::flatten(self::table('resources'), $module);
    }

    /** @return array<int, class-string> */
    public static function pages(?string $module = null): array
    {
        return self::flatten(self::table('pages'), $module);
    }

    /** @return array<int, class-string> */
    public static function widgets(?string $module = null): array
    {
        return self::flatten(self::table('widgets'), $module);
    }

    /**
     * Reportable subjects — reports-expansion-plan.md Phase 6, item 1.
     *
     * Declared per module like everything else here, so a dataset over payslips
     * belongs to Payroll rather than to a central list every module has to edit.
     *
     * @return array<int, class-string<\App\Support\Reporting\Dataset>>
     */
    public static function datasets(?string $module = null): array
    {
        return self::flatten(self::table('datasets'), $module);
    }

    /** @return array<int, class-string> */
    public static function models(?string $module = null): array
    {
        if ($module !== null) {
            return array_values(self::table('models')[$module] ?? []);
        }

        return array_values(self::morphMap());
    }

    /** @return array<int, string> */
    public static function permissionGroups(?string $module = null): array
    {
        return self::flatten(self::table('permission_groups'), $module);
    }

    /**
     * The module owning a permission group, or null for a group no module claims
     * (which PermissionCoverageTest treats as a failure, not a default).
     */
    public static function moduleForPermissionGroup(string $group): ?string
    {
        foreach (self::table('permission_groups') as $module => $groups) {
            if (in_array($group, $groups, true)) {
                return $module;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<int, mixed>>  $table
     * @return array<int, mixed>
     */
    private static function flatten(array $table, ?string $module): array
    {
        if ($module !== null) {
            return array_values($table[$module] ?? []);
        }

        return array_values(array_merge(...array_values($table)));
    }
}
