<?php

namespace App\Support\Reporting;

use App\Support\ModuleMap;
use App\Support\Modules;

/**
 * Every reportable subject the application declares — `docs/reports-expansion-plan.md` Phase 6, item 1.
 *
 * **Assembled from the module manifests, not from a list here**, which is the same decision
 * `ModuleManifest` made for models, resources, pages and widgets: a dataset over payslips belongs to Payroll,
 * so Payroll declares it, and a central list would be a file every module has to edit — the thing that
 * document was written to remove. It also means a dataset arrives with a module already attached, which is
 * what `isAvailable()` gates on.
 *
 * **Keyed on `ModuleMap::alias()`.** Phase 6.3 stores that key in a saved definition, so it is a storage
 * format and lives in `tests/alias-lock.json` with every other one. A dataset class may move; its key may not.
 *
 * **`available()` is the only list anything user-facing should read.** `all()` is what the guards enumerate;
 * the difference is a module this company has not bought and a permission this person does not hold, and
 * conflating the two is how a dropdown offers to build a report over payroll to somebody who cannot see a
 * payslip.
 */
final class DatasetRegistry
{
    /**
     * Every declared dataset, key => class.
     *
     * @return array<string, class-string<Dataset>>
     */
    public static function all(): array
    {
        $datasets = [];

        foreach (ModuleMap::datasets() as $class) {
            $datasets[$class::key()] = $class;
        }

        return $datasets;
    }

    /**
     * The datasets this company has and this person may report on.
     *
     * @return array<string, class-string<Dataset>>
     */
    public static function available(): array
    {
        return array_filter(self::all(), fn (string $class): bool => $class::isAvailable());
    }

    /**
     * One dataset by key, or none — and only if it is available.
     *
     * The gate is here rather than at each caller, because "look up the dataset a saved definition names" is
     * exactly the path a definition written when a module was licensed travels down after it is switched off.
     * A definition naming a subject somebody may not report on resolves to nothing, which every caller has to
     * handle anyway for a key that was never a dataset at all.
     *
     * @return class-string<Dataset>|null
     */
    public static function find(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        return self::available()[$key] ?? null;
    }

    /**
     * Labels for the builder's subject picker, in module order then alphabetically.
     *
     * Grouped by module rather than flat: with eleven subjects the useful question is "what can I report on
     * about invoicing", and the module a subject belongs to is the answer somebody already has in mind.
     *
     * @return array<string, array<string, string>>
     */
    public static function labels(): array
    {
        $grouped = [];

        foreach (self::available() as $key => $class) {
            $grouped[Modules::label($class::module())][$key] = $class::label();
        }

        ksort($grouped);

        foreach ($grouped as &$labels) {
            asort($labels);
        }

        return $grouped;
    }
}
