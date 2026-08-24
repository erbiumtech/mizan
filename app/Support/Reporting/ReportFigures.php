<?php

namespace App\Support\Reporting;

/**
 * How a figure is written on a report — `docs/reports-expansion-plan.md` Phase 4.3.
 *
 * "Negatives in parentheses, and a company-wide preference for it. Accountants read `(1,250)`."
 *
 * **A preference and not a default**, which is the whole reason it is a setting: `(1,250)` is how a balance
 * sheet is read and `-1,250` is how everyone else reads a spreadsheet, and this application prints both kinds
 * of report to both kinds of reader. Off unless a company turns it on, so nothing changes underneath anybody.
 *
 * **Applied where a report is drawn, not where it is calculated.** Fifty-one reports format their own cells
 * with `number_format()` before the payload ever leaves the service, so a preference honoured in each of them
 * would be fifty-one places to forget. Instead the display layer re-reads what the payload already declares —
 * the `numeric` column list, which is the same thing the exporter uses — and rewrites only those cells. One
 * rule, five call sites, and the reports themselves know nothing about it.
 *
 * **The CSV export deliberately does not get this.** A spreadsheet reads `(1,250)` as text, so the export
 * unformats in the opposite direction and takes the payload's own `-1,250`. That is why this lives in the
 * views rather than in the payload: a transform applied to the payload would reach the CSV, and the one place
 * parentheses are actively harmful is the one file somebody opens in order to do arithmetic.
 *
 * **Notes and labels are untouched.** A note is a sentence — "CASH FELL OVER THE PERIOD" — and a report whose
 * prose said "(1,250)" mid-sentence would be harder to read, not easier. Only cells in columns a report has
 * declared numeric are rewritten.
 *
 * No caching here: `setting()` goes through `TenantSettings`, which already holds one array per tenant, so a
 * lookup per cell is an array read. A static cache in this class would be the classic tenant leak — the first
 * company's preference applied to the second company's report in the same worker.
 */
class ReportFigures
{
    /**
     * A whole negative number, optionally with a trailing percent sign, and nothing else.
     *
     * Anchored on purpose. `-1,250 overdue` is prose that begins with a number and must not be rewritten,
     * and neither must an em dash, a bare hyphen, or a date like `2027-02-20` — all four appear in these
     * reports' numeric columns.
     */
    private const NEGATIVE = '/^-((?:\d{1,3}(?:,\d{3})*|\d+)(?:\.\d+)?)(%?)$/';

    public static function parenthesised(): bool
    {
        return (bool) setting('reports.negatives_in_parentheses', false);
    }

    /**
     * A raw number, as the report should show it.
     *
     * For the statement and ledger kinds and for the tiles, whose payloads carry floats rather than
     * pre-formatted strings.
     */
    public static function money(float|int|string|null $value, int $decimals = 0): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $number = (float) $value;
        $formatted = number_format(abs($number), $decimals);

        // A figure that rounds away to nothing is neither negative nor positive. `(0)` reads as a puzzle and
        // `-0` reads as a bug, and both are what happens when the sign is decided before the rounding is —
        // `number_format(-0.4, 0)` is the case.
        if ($number >= 0 || self::roundsToZero($formatted)) {
            return $formatted;
        }

        return self::parenthesised() ? '('.$formatted.')' : '-'.$formatted;
    }

    /** Whether a formatted figure has nothing left in it but separators and noughts. */
    private static function roundsToZero(string $formatted): bool
    {
        return trim($formatted, '0,.') === '';
    }

    /**
     * An already-formatted cell, rewritten if it is a negative number and the company asked for parentheses.
     *
     * Left exactly as it is when the preference is off, when the cell is not wholly a number, or when it is
     * positive — so this is invisible on every report of every company that has not opted in.
     */
    public static function cell(string $cell): string
    {
        if (! self::parenthesised()) {
            return $cell;
        }

        $cell = trim($cell);

        if (! preg_match(self::NEGATIVE, $cell, $matches)) {
            return $cell;
        }

        // The same rule `money()` applies, because the two have to agree: a report class that ran
        // `number_format(-0.4, 0)` itself has already produced the string `-0`, and `(0)` is no better a
        // rendering of it here than there.
        return self::roundsToZero($matches[1])
            ? $matches[1].$matches[2]
            : '('.$matches[1].$matches[2].')';
    }
}
