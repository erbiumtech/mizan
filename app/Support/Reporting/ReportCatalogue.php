<?php

namespace App\Support\Reporting;

/**
 * The reports the Reports hub offers, contributed by the modules that own them.
 *
 * `Core\Filament\Pages\Reports` held a `const SECTIONS` naming eighteen page classes across four modules,
 * plus the descriptions that make the hub read as a set of questions rather than a menu. That is Core
 * enumerating other modules — thirty-six imports in eight files was §9's count, and nineteen of them were
 * here. See docs/module-packaging-plan.md §9.
 *
 * `Reports.php:43-45` used to explain itself with *"lives in Core, not Accounting, because it spans four
 * modules"*. §9's reply is the right one: something spanning four modules belongs **above** all four, and
 * "above" in this application means a registry the four write to rather than a file in the one module that
 * is always licensed.
 *
 * Sections are ordered by the order they are first registered, and reports within a section likewise, so
 * the reading order of the hub is `bootstrap/providers.php` order — which is why each registration passes a
 * `section` and the sections are declared in one place below rather than invented per module.
 */
class ReportCatalogue
{
    /**
     * The sections, in reading order, with the reports registered into each.
     *
     * Declared here rather than created on first use so the hub's order is a decision instead of a
     * consequence of provider order. A module registering into an unknown section is a programming error
     * worth failing on — but not at boot, which would take the panel down for a typo, so an unknown
     * section is appended and `ReportsHubTest` is what notices.
     *
     * @var array<string, array<class-string, string>>
     */
    private static array $sections = [
        'Financial statements' => [],
        'Receivables & payables' => [],
        'Payroll & tax' => [],
        'Statutory reporting' => [],
        'Ledgers & books' => [],
        'Bank files' => [],

        /*
         * The sections `docs/reports-expansion-plan.md` Phase 0.1 asks for.
         *
         * Declared here, empty, rather than created on first registration — the plan's reason is that
         * "section order is the reading order in both the hub and the sidebar column, so decide it once",
         * and a section that appears when a module happens to boot is a reading order decided by
         * `bootstrap/providers.php`. Empty sections are dropped by `sections()`, so a company without CRM
         * sees no *Sales & pipeline* heading rather than an empty one.
         *
         * After the financial ones, because a company opening the hub is usually there for the statements —
         * and these three read as the questions the rest of the application answers.
         */
        'Sales & pipeline' => [],
        'People & payroll' => [],
        'Operations' => [],
    ];

    /**
     * @param  string  $section  one of the declared sections
     * @param  class-string  $page  the report's own Filament page
     * @param  string  $description  what the report answers, in the words the hub shows
     */
    public static function register(string $section, string $page, string $description): void
    {
        self::$sections[$section] ??= [];
        self::$sections[$section][$page] = $description;
    }

    /**
     * Section => [page class => description], with empty sections dropped.
     *
     * Empty sections are dropped here rather than by the caller because a section is empty for two
     * different reasons — no module registered into it, or the module that would have is not installed —
     * and neither should leave a heading with nothing under it.
     *
     * @return array<string, array<class-string, string>>
     */
    public static function sections(): array
    {
        return array_filter(self::$sections, fn (array $reports): bool => $reports !== []);
    }

    /**
     * Every registered report, ungrouped.
     *
     * @return array<int, class-string>
     */
    public static function pages(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::sections()))) ?: [];
    }

    public static function flush(): void
    {
        self::$sections = array_map(fn (): array => [], self::$sections);
    }
}
