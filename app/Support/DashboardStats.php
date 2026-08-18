<?php

namespace App\Support;

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
     * @param  string  $key  a stable name, so a module can be asked what it contributed
     * @param  Closure(): (array<int, mixed>|mixed|null)  $resolver  returns the stat(s), or null to show none
     * @param  int  $sort  lower sorts first; the dashboard's reading order is a decision, not discovery order
     */
    public static function register(string $key, Closure $resolver, int $sort = 100): void
    {
        self::$stats = array_values(array_filter(self::$stats, fn (array $s): bool => $s['key'] !== $key));

        self::$stats[] = ['key' => $key, 'sort' => $sort, 'resolver' => $resolver];
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

        return $resolved;
    }

    /** @return array<int, string> the keys currently registered, for tests and for the module page */
    public static function keys(): array
    {
        return array_column(self::$stats, 'key');
    }

    public static function flush(): void
    {
        self::$stats = [];
    }
}
