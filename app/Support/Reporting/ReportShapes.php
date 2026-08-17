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
