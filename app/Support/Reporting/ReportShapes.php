<?php

namespace App\Support\Reporting;

use App\Modules\Core\Models\Company;

/**
 * The shapes every report in the pane is drawn in.
 *
 * These four helpers — a table, a file summary, an amount, a subtitle — were private methods on
 * `Accounting\Support\ReportPane`, which is how a class that renders *every report in the application*
 * came to live inside one module and import three others. `docs/module-packaging-plan.md` §8 Group A
 * names that precisely: "host-application code filed inside a module … neither belongs to Accounting,
 * and neither belongs to any package."
 *
 * So the shapes live here, in shared code that imports no module but Core, and each module renders its
 * own reports with them — Payroll its three, Invoicing its three, Accounting the rest. A report's
 * *appearance* stays uniform because it comes from one place; its *contents* stop being Accounting's
 * business.
 */
trait ReportShapes
{
    /**
     * One table, described the same way whatever the report.
     *
     * `grid` is CSS rather than a column count because the columns are not interchangeable: an account
     * name wants the slack and a figure wants a fixed width, and each report knows which of its own are
     * which. `numeric` says which to right-align — a column of amounts read down the left edge is a
     * column nobody can add up.
     *
     * `wide` is for the reports whose columns are data rather than a fixed set — an employee-by-project
     * matrix, a day-by-employee register. See the note on the returned key.
     *
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, array<string, mixed>>  $tiles
     * @return array<string, mixed>
     */
    public function table(
        string $key,
        string $title,
        string $subtitle,
        array $columns,
        string $grid,
        array $numeric,
        array $rows,
        array $tiles,
        string $note,
        ?array $footer = null,
        string $empty = 'Nothing to show for this period.',
        bool $wide = false,
    ): array {
        return [
            'kind' => 'table',
            'key' => $key,
            'title' => $title,
            'subtitle' => $subtitle,
            'columns' => $columns,
            'grid' => $grid,
            'numeric' => $numeric,
            'rows' => $rows,
            'tiles' => $tiles,
            'note' => $note,
            // A row across the bottom, one cell per column, so a figure sits under the column it totals
            // rather than in a single "total" cell that lines up with nothing.
            'footer' => $footer,
            'footer_span' => $footer === null ? 1 : self::span($footer),
            'empty' => $empty,
            /*
             * Whether this table is wider than the pane and must scroll sideways —
             * `docs/reports-expansion-plan.md` Phase 0.2, and the answer to the `matrix` question it asks.
             *
             * That phase proposed a `matrix` kind for the employee-by-column reports, on the grounds that
             * `table` "can render but not total per column beyond one footer row". Looking at it, `table`
             * already does both halves: `columns` is an array, so the columns can be data, and the footer
             * row *is* the per-column total — a totals column on the right is one more column the report
             * computes. There is nothing for a second kind to add, and a second renderer is a second place
             * to fix a drill-through or a sticky header.
             *
             * What `table` genuinely could not do is be wider than the screen. `.fi-explorer-statement` is
             * `overflow: clip`, for its rounded corners, so a twelve-project matrix was **silently cut
             * off** — no scrollbar, no hint, columns simply absent. That is the bug behind the request, and
             * one wrapper fixes it for every wide report in Phases 2 and 3 as well.
             *
             * Declared rather than measured, and only true where a report says so, because the wrapper
             * costs something: a horizontal scroll container re-parents `position: sticky`, so a wide
             * table's header and record row stop sticking to the viewport. A matrix trades that for
             * columns that exist; a two-column report should not pay it.
             */
            'wide' => $wide,
            'balanced' => true,
        ];
    }

    /**
     * How many columns the footer's label runs across: itself plus the blank cells after it.
     *
     * A register's figures start in the fourth column, so its label has three columns of room and needs
     * them — the first of those is 7rem wide, which fits "Closing" and not the rest of the sentence. Worked
     * out from the footer rather than declared per report so a report cannot state one and mean the other.
     *
     * @param  array<int, string>  $footer
     */
    private static function span(array $footer): int
    {
        $span = 1;

        while ($span < count($footer) && $footer[$span] === '') {
            $span++;
        }

        return $span;
    }

    /**
     * A file report: what the file would contain, without producing it.
     *
     * Shared because the shape is the same for all three — a count, a value, and a sentence — while only
     * the figures differ, and those are the part each module owns.
     *
     * @return array<string, mixed>
     */
    public function fileReport(string $key, string $title, int $rows, float $total, string $unit, string $period): array
    {
        return [
            'kind' => 'file',
            'key' => $key,
            'title' => $title,
            'subtitle' => $this->subtitle($period),
            'tiles' => [
                ['label' => mb_strtoupper($unit), 'value' => (float) $rows, 'accent' => false],
                ['label' => 'TOTAL VALUE', 'value' => $total, 'accent' => true],
            ],
            'note' => $rows > 0
                ? mb_strtoupper('the file would carry '.$rows.' '.mb_strtolower($unit))
                : 'NOTHING TO SEND FOR THIS PERIOD',
            'rows_count' => $rows,
            'balanced' => true,
        ];
    }

    /** A figure, or nothing where a nought would be noise. Ledger columns are read down, not added up. */
    public function amount(float|int|string|null $value): string
    {
        return (float) $value ? number_format((float) $value, 0) : '';
    }

    /** Whose figures these are, and for what period — the line under every report's title. */
    public function subtitle(string $period): string
    {
        return trim((Company::current()?->name ?? '').' · '.$period, ' ·');
    }
}
