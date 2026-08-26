<?php

namespace App\Support;

/**
 * Numbers written for people: no trailing zeros, no missing digits.
 *
 * This exists because the obvious one-liner is wrong in a way that is invisible until it reaches a
 * document somebody signs:
 *
 * ```php
 * rtrim(rtrim((string) $percent, '0'), '.')   // 10 → "1"   20 → "2"   100 → "1"
 * ```
 *
 * `rtrim` strips *characters*, not a decimal fraction. Given `"10"` it removes the trailing `0` and
 * returns `"1"` — a tenth of the figure, silently. The pattern only behaves when the value already
 * carries a decimal point, so a `decimal:2` cast (`"10.00"`) survives it and a plain int or float does
 * not. That is why it lasted: the columns that broke were the ones with no cast.
 *
 * It was found on a payment certificate reading **"Retention @ 1%"** against a contract holding 10%, and
 * on a contractual delay notice offering **"1 day(s) of extension"** where ten were granted.
 *
 * Formatting to a fixed precision first gives `rtrim` a decimal point to work against, which is what
 * every correct call site in this codebase was already doing by hand.
 */
class Num
{
    /**
     * `10 → "10"`, `12.50 → "12.5"`, `0 → "0"`, `null → ""`.
     *
     * @param  int  $decimals  the most places to keep; trailing zeros within them are dropped
     */
    public static function trim(int|float|string|null $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // No thousands separator: this is a figure to be trimmed, and a comma would survive the rtrim and
        // read as part of the number. Callers wanting groups want number_format directly.
        $formatted = number_format((float) $value, max(0, $decimals), '.', '');

        $trimmed = $decimals > 0
            ? rtrim(rtrim($formatted, '0'), '.')
            : $formatted;

        // "0.00" trims to "" rather than "0", and a percentage that reads as blank is worse than one
        // that reads as zero.
        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    /** The same, with a percent sign. `10 → "10%"`. */
    public static function percent(int|float|string|null $value, int $decimals = 2): string
    {
        $trimmed = self::trim($value, $decimals);

        return $trimmed === '' ? '' : $trimmed.'%';
    }
}
