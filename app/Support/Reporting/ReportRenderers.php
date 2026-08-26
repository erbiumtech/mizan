<?php

namespace App\Support\Reporting;

use Closure;

/**
 * Reports rendered by the module that owns them, rather than by Accounting on their behalf.
 *
 * `Accounting\Support\ReportPane` drew all seventeen reports in the Reports explorer, including three
 * belonging to Payroll and three to Invoicing — so it imported `WithholdingTaxSummary`,
 * `SalaryBankExportService`, `Payslip`, `InvoiceService` and `FbrReconciliation`, and a single rendering
 * class was most of what kept Accounting, Payroll and Invoicing from being packaged apart.
 * `docs/module-packaging-plan.md` §8 Group A calls for exactly this: each module renders its own.
 *
 * A renderer is a closure rather than a class implementing an interface, deliberately. What it must
 * return is an array in one of four shapes, and those shapes are described and enforced by
 * `ReportPaneTest` — a `render(): array` interface would restate the signature without constraining the
 * part that matters. What a module gains from the indirection is that it can build that array however it
 * likes, using `ReportShapes` if it wants the standard look.
 *
 * The pane keeps rendering Accounting's own eleven directly. Moving those too would be indirection for
 * its own sake: they are already in the module that owns them.
 *
 * **A family is the second kind of registration, for reports whose keys are rows rather than classes**
 * — `docs/reports-expansion-plan.md` Phase 6, item 4. Every coded report's key is a page's basename and
 * therefore known when the provider boots; a *built* report's key names a `report_definitions` row, so
 * there is nothing to register one per key against. A family registers the prefix instead and is handed
 * the key, which keeps the routing here — in the one place the pane, `NoReportPane` and each report's own
 * page all already ask — rather than teaching each of those three what a custom report is.
 */
class ReportRenderers
{
    /**
     * Renderer per report key, keyed on the Reports hub's own catalogue keys (class basenames).
     *
     * @var array<string, Closure>
     */
    private static array $renderers = [];

    /**
     * Renderer per key *prefix*, longest prefix first at match time.
     *
     * @var array<string, Closure>
     */
    private static array $families = [];

    /**
     * @param  string  $key  the report's catalogue key, e.g. `TaxSummary`
     * @param  Closure(string $asOf, bool $comparison, array<string, mixed> $asked): (array<string, mixed>|null)  $renderer
     */
    public static function register(string $key, Closure $renderer): void
    {
        self::$renderers[$key] = $renderer;
    }

    /**
     * A renderer for every key beginning with `$prefix`.
     *
     * The closure takes the **key** as well, because that is the only thing distinguishing one report in
     * the family from another — see `App\Support\Reporting\BuiltReport::forKey()`.
     *
     * @param  Closure(string $key, string $asOf, bool $comparison, array<string, mixed> $asked): (array<string, mixed>|null)  $renderer
     */
    public static function registerFamily(string $prefix, Closure $renderer): void
    {
        self::$families[$prefix] = $renderer;
    }

    /**
     * Whether anything renders this key.
     *
     * **A family match is syntactic and deliberately does not check that the row exists.** This is asked
     * once per report in the hub while the list is drawn, and a database read per row is exactly the shape
     * of fault `docs/page-load-performance-plan.md` is about. The hub only ever lists keys of definitions
     * the reader may read, and `render()` answers null for anything else — which is what the pane already
     * does for a report it cannot draw.
     */
    public static function has(?string $key): bool
    {
        return $key !== null
            && (isset(self::$renderers[$key]) || self::familyFor($key) !== null);
    }

    /**
     * @param  array<string, mixed>  $asked
     * @return array<string, mixed>|null
     */
    public static function render(string $key, string $asOf, bool $comparison, array $asked): ?array
    {
        // An exact key first, so a family can never shadow a coded report that happens to share its
        // prefix — and so the eighteen registrations that existed before families did are untouched.
        if (isset(self::$renderers[$key])) {
            return self::$renderers[$key]($asOf, $comparison, $asked);
        }

        $family = self::familyFor($key);

        return $family === null ? null : $family($key, $asOf, $comparison, $asked);
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::$renderers);
    }

    public static function flush(): void
    {
        self::$renderers = [];
        self::$families = [];
    }

    /** The family a key belongs to, preferring the longest prefix so a narrower family always wins. */
    private static function familyFor(string $key): ?Closure
    {
        $matched = null;
        $length = -1;

        foreach (self::$families as $prefix => $renderer) {
            if (str_starts_with($key, $prefix) && mb_strlen($prefix) > $length) {
                $matched = $renderer;
                $length = mb_strlen($prefix);
            }
        }

        return $matched;
    }
}
