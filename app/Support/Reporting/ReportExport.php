<?php

namespace App\Support\Reporting;

use Illuminate\Support\Carbon;

/**
 * Any report the pane can draw, as a flat grid — `docs/reports-expansion-plan.md` Phase 4.1.
 *
 * "Export the open pane — PDF and CSV. One implementation on the pane rather than per report — and the one
 * Phase 8 sends, which is why that phase waits for this one rather than growing a second renderer."
 *
 * **One implementation means one normalisation.** The pane draws four kinds and three of them have different
 * shapes: a `table` is columns and rows, a `ledger` is columns and *sections* of rows each with its own
 * total, and a `statement` is label-and-amount rows with an optional prior-year column. Fifty-one reports
 * produce those three shapes between them, so the exporter flattens all three into one grid and the CSV
 * writer and the PDF template both read only that. A per-kind exporter would have been three writers and
 * three templates, and Phase 8 would have had to pick one.
 *
 * **The fourth kind cannot be exported, and that is not a gap.** A `file` report — the salary bank file, the
 * FBR tax file, the bank payment file — *is* a download already; its screen describes the file rather than
 * containing it, and a CSV of that screen would be a CSV about a file rather than the file.
 *
 * **The interesting part is undoing the formatting.** The pane exists to make figures readable: `275,000`
 * with a thousands separator, an em dash where a value does not apply. Both are wrong in a spreadsheet —
 * `275,000` arrives as text in most importers and an em dash arrives as text in all of them, so a column
 * somebody wants to sum comes in as fifty strings. So the CSV strips separators from the columns the report
 * has declared numeric and empties the dashes, which is the one place in this application where the display
 * layer is deliberately reversed. The PDF keeps the formatting, because a PDF is for reading.
 *
 * **And it escapes formulas.** Report cells carry text somebody typed — a project name, a checklist item, a
 * delivery failure reason — and a spreadsheet treats a cell beginning `=`, `+`, `-` or `@` as a formula to
 * execute. That is a real attack against whoever opens the export rather than against this application, and
 * the mitigation has to be careful in one respect: a negative number legitimately begins with `-`, so the
 * escape applies only to cells that are not numbers.
 */
class ReportExport
{
    /**
     * A report whose screen describes a download rather than containing one.
     *
     * **Defence in depth rather than the operative refusal, and worth being honest about.** A `file` payload
     * carries no `columns` and no `rows` at all — only tiles, a note and a count — so `grid()` produces
     * nothing for it and the empty-rows check below would refuse it anyway. No test can distinguish the two
     * reasons, and none pretends to. This stays because it states the intent, and because it is what would
     * still refuse a `file` report if somebody later taught `grid()` how to flatten one.
     *
     * @var array<int, string>
     */
    public const UNEXPORTABLE_KINDS = ['file'];

    /** Characters a spreadsheet reads as the start of a formula. */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /** Whether there is anything here that can be written to a file. */
    public function supports(?array $statement): bool
    {
        return $statement !== null
            && ! in_array($statement['kind'] ?? '', self::UNEXPORTABLE_KINDS, true)
            && $this->grid($statement)['rows'] !== [];
    }

    /**
     * Any pane payload as columns, rows and a footer.
     *
     * Section labels and section totals become ordinary rows, because a grid is what both a CSV and a
     * printed page can hold — neither has anywhere to put a heading that is not a row.
     *
     * `sections` lists which row indexes are headings rather than data. The PDF needs to know and the CSV
     * does not — and it is returned rather than inferred from the row's shape, because a heading is a fact
     * the exporter has and a one-filled-cell row is a guess a genuinely single-column report would fail.
     *
     * @param  array<string, mixed>  $statement
     * @return array{title: string, subtitle: string, columns: array<int, string>, rows: array<int, array<int, string>>, footer: array<int, string>|null, note: string, tiles: array<int, array<string, mixed>>, numeric: array<int, int>, sections: array<int, int>}
     */
    public function grid(array $statement): array
    {
        $grid = match ($statement['kind'] ?? null) {
            'statement' => $this->fromStatement($statement),
            'ledger' => $this->fromLedger($statement),
            'table' => $this->fromTable($statement),
            default => ['columns' => [], 'rows' => [], 'footer' => null, 'numeric' => [], 'sections' => []],
        };

        return [
            'title' => (string) ($statement['title'] ?? 'Report'),
            'subtitle' => (string) ($statement['subtitle'] ?? ''),
            'note' => (string) ($statement['note'] ?? ''),
            'tiles' => array_values($statement['tiles'] ?? []),
            ...$grid,
        ];
    }

    /**
     * A `table` payload, which is already a grid.
     *
     * Passed through rather than rebuilt, and the columns are the pane's own. A CSV that added the account
     * code — genuinely useful as a join key, and genuinely absent from the screen — would be a different
     * report from the one somebody exported, and "the same thing on two screens" is a property worth more
     * than a convenient extra column.
     *
     * @param  array<string, mixed>  $statement
     * @return array{columns: array<int, string>, rows: array<int, array<int, string>>, footer: array<int, string>|null, numeric: array<int, int>, sections: array<int, int>}
     */
    private function fromTable(array $statement): array
    {
        return [
            'columns' => array_map('strval', $statement['columns'] ?? []),
            'rows' => array_map(fn (array $row): array => array_map('strval', $row), $statement['rows'] ?? []),
            'footer' => isset($statement['footer']) && $statement['footer'] !== null
                ? array_map('strval', $statement['footer'])
                : null,
            'numeric' => array_map('intval', $statement['numeric'] ?? []),
            // A table has no headings inside it; its one heading is the column row.
            'sections' => [],
        ];
    }

    /**
     * A `ledger` payload: sections of rows, each with its own total.
     *
     * The section label occupies the first column of a row of its own, which is how a printed ledger has
     * always looked and the only thing a CSV can do with a heading.
     *
     * @param  array<string, mixed>  $statement
     * @return array{columns: array<int, string>, rows: array<int, array<int, string>>, footer: array<int, string>|null, numeric: array<int, int>, sections: array<int, int>}
     */
    private function fromLedger(array $statement): array
    {
        $width = count($statement['columns'] ?? []);
        $rows = [];
        $headings = [];

        foreach ($statement['sections'] ?? [] as $section) {
            $headings[] = count($rows);
            $rows[] = $this->pad([(string) ($section['label'] ?? '')], $width);

            foreach ($section['rows'] ?? [] as $row) {
                $rows[] = $this->pad(array_map('strval', $row['cells'] ?? []), $width);
            }

            if (isset($section['total']['cells'])) {
                $rows[] = $this->pad(array_map('strval', $section['total']['cells']), $width);
            }
        }

        return [
            'columns' => array_map('strval', $statement['columns'] ?? []),
            'rows' => $rows,
            'footer' => null,
            'numeric' => array_map('intval', $statement['numeric'] ?? []),
            'sections' => $headings,
        ];
    }

    /**
     * A `statement` payload: label, this period, and optionally the last one.
     *
     * The amounts here are raw floats rather than formatted strings — unlike the other two kinds — so they
     * are formatted on the way out, and the CSV then unformats them again. That looks circular and is not:
     * the grid is what both outputs read, and only one of the two outputs wants a bare number.
     *
     * The closing line is emitted last and outside every section, because that is what it is: a statement's
     * bottom line belongs to the statement rather than to its final section.
     *
     * @param  array<string, mixed>  $statement
     * @return array{columns: array<int, string>, rows: array<int, array<int, string>>, footer: array<int, string>|null, numeric: array<int, int>, sections: array<int, int>}
     */
    private function fromStatement(array $statement): array
    {
        $hasPrevious = ($statement['previous_label'] ?? null) !== null;

        $columns = array_values(array_filter([
            '',
            (string) ($statement['current_label'] ?? 'This period'),
            $hasPrevious ? (string) $statement['previous_label'] : null,
        ], fn (?string $value): bool => $value !== null));

        $width = count($columns);
        $rows = [];
        $headings = [];

        foreach ($statement['sections'] ?? [] as $section) {
            $headings[] = count($rows);
            $rows[] = $this->pad([(string) ($section['label'] ?? '')], $width);

            foreach ($section['rows'] ?? [] as $row) {
                $rows[] = $this->statementRow($row, $hasPrevious);
            }

            if (isset($section['total'])) {
                $rows[] = $this->statementRow($section['total'], $hasPrevious);
            }
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'footer' => isset($statement['closing'])
                ? $this->statementRow($statement['closing'], $hasPrevious)
                : null,
            // Every column but the label.
            'numeric' => range(1, max(1, $width - 1)),
            'sections' => $headings,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function statementRow(array $row, bool $hasPrevious): array
    {
        $cells = [(string) ($row['label'] ?? ''), $this->money($row['current'] ?? null)];

        if ($hasPrevious) {
            $cells[] = $this->money($row['previous'] ?? null);
        }

        return $cells;
    }

    /** Nought is a figure and null is not: a statement line with no prior year has an empty cell, not a nought. */
    private function money(mixed $value): string
    {
        return $value === null ? '' : number_format((float) $value, 0);
    }

    /**
     * @param  array<int, string>  $cells
     * @return array<int, string>
     */
    private function pad(array $cells, int $width): array
    {
        return array_slice(array_pad($cells, $width, ''), 0, max($width, count($cells)));
    }

    /**
     * The whole report as CSV.
     *
     * Title and subtitle first, then a blank line, then the grid — so the file says which report it is and
     * whose figures at what date, which a bare table of numbers in somebody's Downloads folder does not. The
     * note goes last for the same reason: it is the report's own summary and the thing a reader would
     * otherwise have to remember.
     *
     * @param  array<string, mixed>  $statement
     */
    public function csv(array $statement): string
    {
        $grid = $this->grid($statement);

        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [$grid['title']]);

        if ($grid['subtitle'] !== '') {
            fputcsv($handle, [$grid['subtitle']]);
        }

        fputcsv($handle, []);
        fputcsv($handle, array_map(fn (string $c): string => $this->escape($c), $grid['columns']));

        foreach ($grid['rows'] as $row) {
            fputcsv($handle, $this->exportable($row, $grid['numeric']));
        }

        if ($grid['footer'] !== null) {
            fputcsv($handle, $this->exportable($grid['footer'], $grid['numeric']));
        }

        if ($grid['note'] !== '') {
            fputcsv($handle, []);
            fputcsv($handle, [$grid['note']]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * One row, with the display formatting undone on the numeric columns.
     *
     * @param  array<int, string>  $row
     * @param  array<int, int>  $numeric
     * @return array<int, string>
     */
    private function exportable(array $row, array $numeric): array
    {
        $out = [];

        foreach ($row as $index => $cell) {
            $out[] = in_array($index, $numeric, true)
                ? $this->unformat((string) $cell)
                : $this->escape((string) $cell);
        }

        return $out;
    }

    /**
     * A figure a spreadsheet will read as a figure.
     *
     * Applied to the declared numeric columns only, which matters for the dash: in a numeric column a dash
     * is a figure that does not apply and an empty cell says that properly, but in a *text* column it is the
     * pane's own wording — the serial number a device does not have — and emptying it would delete a value
     * somebody chose to show.
     *
     * Thousands separators removed, and an em dash emptied rather than passed through. A dash means the
     * value does not apply, and an empty cell is how a spreadsheet says that — where the dash itself would
     * arrive as text and stop the column summing.
     *
     * A percentage keeps its sign: `75.00%` becomes `75.00%` and not `75`, because dropping the symbol would
     * silently change a proportion into a count. It stays text, and that is the honest outcome.
     */
    private function unformat(string $cell): string
    {
        $cell = trim($cell);

        if ($cell === '—' || $cell === '–' || $cell === '-' || $cell === '') {
            return '';
        }

        $bare = str_replace(',', '', $cell);

        return is_numeric($bare) ? $bare : $this->escape($cell);
    }

    /**
     * A cell a spreadsheet will not execute.
     *
     * Excel and its imitators treat a leading `=`, `+`, `-` or `@` as a formula, so a project named
     * `=cmd|...` in a report somebody exports and opens becomes code running on their machine. Prefixed with
     * an apostrophe, which every spreadsheet reads as "this is text".
     *
     * **Numbers are left alone**, and that exception is the whole difficulty: a negative figure begins with
     * `-`, and escaping it would turn every loss on every report into a string. So the numeric check comes
     * first and only genuine text is escaped.
     */
    private function escape(string $cell): string
    {
        if ($cell === '' || is_numeric($cell)) {
            return $cell;
        }

        return in_array($cell[0], self::FORMULA_PREFIXES, true) ? "'".$cell : $cell;
    }

    /**
     * What the file is called.
     *
     * The report and the date it was drawn to, because two exports of the same report at different dates are
     * the commonest pair of files anybody has in a Downloads folder and `report.csv` twice tells them apart
     * not at all.
     *
     * @param  array<string, mixed>  $statement
     */
    public function filename(array $statement, string $asOf, string $extension): string
    {
        $title = (string) ($statement['title'] ?? $statement['key'] ?? 'report');

        return str($title)->slug()->value()
            .'-'.Carbon::parse($asOf)->toDateString()
            .'.'.ltrim($extension, '.');
    }
}
