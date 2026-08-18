<?php

namespace App\Support\Contracts;

/**
 * A block of the Company Settings screen, contributed by the module whose settings it edits.
 *
 * `Core\Filament\Pages\CompanySettings` wrote all eight of its sections itself, and two of them needed
 * Accounting models: the base-currency section reads `Currency` and asks `JournalEntryLine` whether anything
 * has been posted, and the payroll-accounts field validates codes against `Account`. That is Core depending
 * on Accounting for a settings form — the last of §9's seven files. See docs/module-packaging-plan.md §9.
 *
 * A section is three things, and it needs all three because settings are not uniformly shaped:
 *
 * - `components()` — what is rendered. Filament schema components, so a section may be one `Section` or
 *   several.
 * - `fill()` — state on mount. Most fields come straight from `setting()`, but the base currency is a *row*
 *   in the currencies table, not a setting, so "read the keys back" would not have covered it.
 * - `save(array $state)` — what to write. Not a list of keys for the same reason: changing the base currency
 *   updates a model that refuses the change once entries are posted, and one section already logs its
 *   changes to the activity log rather than only writing them.
 *
 * The page still owns the shell — access, the header action, `$data`, validation dispatch, the save
 * notification and the status-page cache bust — and no longer knows what is in these sections.
 *
 * **`fill()` keys and the field names in `components()` must agree**, because `save()` is handed the page's
 * whole state and reads its own keys out of it. Keys are flat and prefixed by convention
 * (`accounting_payroll_accounts`), which also keeps the validation error attribute — `data.<field>` — the
 * name the existing tests assert on.
 */
interface SettingsSection
{
    /** A stable name for this section, so a module can be asked what it contributed. */
    public function key(): string;

    /**
     * The Filament schema components this section renders.
     *
     * @return array<int, mixed>
     */
    public function components(): array;

    /**
     * The state this section's fields start with, keyed by field name.
     *
     * @return array<string, mixed>
     */
    public function fill(): array;

    /**
     * Persist this section's fields.
     *
     * `$state` is the whole form's state, not only this section's, so a section whose fields were hidden can
     * tell — an absent key means the section was never rendered, and writing a value nobody chose is the bug
     * that guard exists to prevent.
     *
     * @param  array<string, mixed>  $state
     */
    public function save(array $state): void;
}
