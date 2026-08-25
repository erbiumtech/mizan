<?php

namespace App\Support;

use App\Modules\Core\Models\Company;
use Closure;

/**
 * Headline stats each module contributes to the dashboard's overview widget.
 *
 * `OperationsOverview` shows four figures — active employees, journal entries awaiting approval, unpaid
 * invoices, products at reorder level — from four different modules, each behind its own permission
 * check. It lived in Accounting and imported Employees, Invoicing and Inventory to do it, which made a
 * *dashboard widget* the reason three modules could not be packaged apart.
 *
 * `docs/module-packaging-plan.md` §8 Group A is exact about what it is: "host-application code filed
 * inside a module … neither belongs to Accounting, and neither belongs to any package." So the widget
 * moved to Core, which is always installed, and the figures it shows are registered by the modules that
 * own them.
 *
 * What this buys beyond the graph: a stat now disappears with its module rather than needing a
 * permission check that happens to be false. A company without Inventory has no reorder-level figure
 * because nothing registered one, not because `ProductView` was never granted.
 */
class DashboardStats
{
    /**
     * Registered contributions, in the order the dashboard should read them.
     *
     * @var array<int, array{key: string, sort: int, resolver: Closure}>
     */
    private static array $stats = [];

    /**
     * Resolved stats, per tenant, for the life of the request — `docs/reports-expansion-plan.md` Phase 5.7.
     *
     * **`OperationsOverview::canView()` resolves these to decide whether to show them, and then `getStats()`
     * resolves them again.** Its own docblock has always said so: "resolving the stats to decide whether to
     * show them means the queries run twice on a dashboard that does display it". Registering that widget in
     * Phase 5.7 — it had never been on the dashboard at all — put the page one query over its budget, and
     * `PanelPerformanceTest` is explicit that query counts get fixed rather than budgeted for.
     *
     * **Keyed on the company, which is not optional.** `docs/page-load-performance-plan.md` names the failure:
     * caching across requests without the tenant in the key is a cross-tenant leak. A test iterating two
     * companies would otherwise see the first one's figures under the second one's name.
     *
     * Cleared by `register()` as well as `flush()`, because a contribution arriving after a resolve would
     * otherwise never appear.
     *
     * @var array<int|string, array<int, mixed>>
     */
    private static array $resolved = [];

    /**
     * @param  string  $key  a stable name, so a module can be asked what it contributed
     * @param  Closure(): (array<int, mixed>|mixed|null)  $resolver  returns the stat(s), or null to show none
     * @param  int  $sort  lower sorts first; the dashboard's reading order is a decision, not discovery order
     */
    public static function register(string $key, Closure $resolver, int $sort = 100): void
    {
        self::$stats = array_values(array_filter(self::$stats, fn (array $s): bool => $s['key'] !== $key));

        self::$stats[] = ['key' => $key, 'sort' => $sort, 'resolver' => $resolver];

        // A registration invalidates whatever was resolved before it.
        self::$resolved = [];
    }

    /**
     * Every registered stat, flattened and in order.
     *
     * A resolver returning null is how a module declines — the permission is not held, or there is
     * nothing worth showing — and it is filtered here so no caller has to.
     *
     * @return array<int, mixed>
     */
    public static function resolve(): array
    {
        $tenant = Company::current()?->getKey() ?? 'none';

        if (array_key_exists($tenant, self::$resolved)) {
            return self::$resolved[$tenant];
        }

        $stats = self::$stats;
        usort($stats, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $resolved = [];

        foreach ($stats as $stat) {
            $value = ($stat['resolver'])();

            if ($value === null) {
                continue;
            }

            foreach (is_array($value) ? $value : [$value] as $one) {
                $resolved[] = $one;
            }
        }

        return self::$resolved[$tenant] = $resolved;
    }

    /** @return array<int, string> the keys currently registered, for tests and for the module page */
    public static function keys(): array
    {
        return array_column(self::$stats, 'key');
    }

    public static function flush(): void
    {
        self::$stats = [];
        self::$resolved = [];
    }
}
