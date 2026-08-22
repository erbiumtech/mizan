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
     * @param  string  $key  the report's catalogue key, e.g. `TaxSummary`
     * @param  Closure(string $asOf, bool $comparison, array<string, mixed> $asked): (array<string, mixed>|null)  $renderer
     */
    public static function register(string $key, Closure $renderer): void
    {
        self::$renderers[$key] = $renderer;
    }

    public static function has(string $key): bool
    {
        return isset(self::$renderers[$key]);
    }

    /**
     * @param  array<string, mixed>  $asked
     * @return array<string, mixed>|null
     */
    public static function render(string $key, string $asOf, bool $comparison, array $asked): ?array
    {
        $renderer = self::$renderers[$key] ?? null;

        return $renderer === null ? null : $renderer($asOf, $comparison, $asked);
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::$renderers);
    }

    public static function flush(): void
    {
        self::$renderers = [];
    }
}
