<?php

namespace App\Support\Reporting;

/**
 * Where each kind of widget sits on the dashboard — `docs/reports-expansion-plan.md` Phase 5.7.
 *
 * "`$sort` set deliberately so the order is money → sales → service → people rather than discovery order —
 * that order becomes the company default a user may depart from in Phase 7."
 *
 * **Constants rather than twenty-three magic numbers.** A widget writing `DashboardWidgets::MONEY + 3` says
 * which band it is in and leaves the arithmetic visible; `protected static ?int $sort = 13` says nothing, and
 * the day somebody inserts a widget between two others they renumber whatever they happen to notice.
 *
 * **Not a registry, deliberately.** The obvious alternative is one list here naming every widget in order —
 * and it would be exactly the coupling `docs/module-packaging-plan.md` §8 calls Group A: host code holding a
 * list of what each module owns, which then has to be edited from outside the module every time a module gains
 * a widget. Each widget states its own position; this only says what the positions mean.
 *
 * **Ten apart** so a band can gain a widget without renumbering its neighbours, and so the gaps are obvious
 * when a band fills up.
 *
 * The order itself is the plan's, and its logic is what a company looks at first: money, then what is coming
 * in, then whether the work is being delivered, then the people doing it. Inventory follows because it is the
 * one group the plan lists last and the one fewest companies license.
 */
class DashboardWidgets
{
    /**
     * Above the bands: the company's own headline figures, whatever it has licensed.
     *
     * `OperationsOverview` alone, and it earns the position by being the only widget assembled from every
     * module's contributions rather than describing one of them.
     */
    public const HEADLINE = 0;

    public const MONEY = 10;

    public const SALES = 20;

    public const SERVICE = 30;

    public const PEOPLE = 40;

    public const INVENTORY = 50;

    /**
     * How wide a band is.
     *
     * Used by the test that checks every widget's sort falls inside one: a sort of 47 is in the people band, a
     * sort of 8 is in none, and "in none" is how discovery order gets back in.
     */
    public const BAND = 10;

    /**
     * The bands, lowest first.
     *
     * @return array<string, int>
     */
    public static function bands(): array
    {
        return [
            'headline' => self::HEADLINE,
            'money' => self::MONEY,
            'sales' => self::SALES,
            'service' => self::SERVICE,
            'people' => self::PEOPLE,
            'inventory' => self::INVENTORY,
        ];
    }

    /** Which band a sort belongs to, or null where it belongs to none. */
    public static function bandFor(?int $sort): ?string
    {
        if ($sort === null) {
            return null;
        }

        foreach (array_reverse(self::bands(), preserve_keys: true) as $name => $floor) {
            if ($sort >= $floor && $sort < $floor + self::BAND) {
                return $name;
            }
        }

        return null;
    }
}
