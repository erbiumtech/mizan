<?php

namespace App\Modules\ConstructionCosting\Support;

/**
 * How far ordered, received and invoiced may drift before somebody has to say why — `docs/construction-management-plan.md` §5.
 *
 * "Tolerances live in config overridable by settings, the `PayrollAccounts` pattern", and this is that pattern: the
 * company's setting first, the shipped default behind it. A blank saved value falls back rather than being taken
 * literally, because a settings form saved without a line is not the same statement as "tolerate nothing".
 *
 * **Zero tolerance is available and is a real choice**, which is why `null` and `0` are distinguished: a company that
 * genuinely wants every weight ticket in front of a human can set the percentage to `0` and this honours it. What it
 * will not do is read an unfilled field that way.
 */
class MatchTolerances
{
    public static function quantityPercent(): float
    {
        return static::value('quantity_percent');
    }

    public static function pricePercent(): float
    {
        return static::value('price_percent');
    }

    /**
     * The absolute floor under which no variance is worth anybody's time.
     *
     * Without it a 2% tolerance on a 500 order flags a 10 difference, and a control that fires on a 10 difference is
     * one people learn to click through — which costs more than the 10.
     */
    public static function minimumAmount(): float
    {
        return static::value('minimum_amount');
    }

    private static function value(string $key): float
    {
        $saved = data_get(setting('construction.match'), $key);

        // `null` and `''` mean the line was never filled in; a literal `0` is a company saying "tolerate nothing",
        // and it is honoured.
        if ($saved === null || $saved === '') {
            $saved = config('construction.match.'.$key);
        }

        return (float) $saved;
    }

    /**
     * Whether a variance is inside tolerance.
     *
     * **Both tests have to fail for it to be worth raising**: it must exceed the percentage *and* the absolute floor.
     * Either alone produces a report nobody reads — the percentage alone flags trivial money on small orders, the
     * floor alone flags every large order that merely rounded.
     */
    public static function withinTolerance(float $variance, float $base, float $percent): bool
    {
        if (abs($variance) < static::minimumAmount()) {
            return true;
        }

        if ($base == 0.0) {
            // Nothing to take a percentage of. A variance against a zero base is outside tolerance by definition,
            // which is the honest answer rather than a division by zero.
            return false;
        }

        return abs($variance / $base) * 100 <= $percent;
    }
}
