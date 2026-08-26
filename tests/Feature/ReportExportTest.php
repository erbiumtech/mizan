<?php

namespace Tests\Feature;

use App\Support\Reporting\ReportExport;
use Tests\TestCase;

/**
 * Exporting the open pane — `docs/reports-expansion-plan.md` Phase 4.1.
 *
 * These are unit tests on the normaliser, with no database: the interesting behaviour is entirely in the
 * shape of the payload and in what happens to the cells on the way out.
 *
 * Three things are actually being asserted here, and only the first is plumbing:
 *
 *  - **one grid from three shapes** — `table`, `ledger` and `statement` all flatten to columns and rows, so
 *    the CSV writer and the PDF template each exist once;
 *  - **the display formatting is undone for the spreadsheet** — `275,000` and `—` are what make the pane
 *    readable and both are wrong in a CSV, where one arrives as text and the other stops a column summing;
 *  - **formulas are escaped, and numbers are not.** A cell beginning `=` is code to a spreadsheet, and a
 *    negative figure begins with `-`, so a careless escape would turn every loss on every report into a
 *    string.
 */
class ReportExportTest extends TestCase
{
    private ReportExport $export;

    protected function setUp(): void
    {
        parent::setUp();

        $this->export = new ReportExport;
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    /** @return array<string, mixed> */
    private function table(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'table',
            'key' => 'AgedReceivables',
            'title' => 'Aged Receivables',
            'subtitle' => 'Acme Ltd · as of 20 Feb 2027',
            'columns' => ['Customer', 'Current', 'Overdue'],
            'numeric' => [1, 2],
            'rows' => [
                ['Karachi Textiles', '275,000', '—'],
                ['Lahore Mills', '40,000', '12,500'],
            ],
            'footer' => ['Total — 2 customers', '315,000', '12,500'],
            'tiles' => [['label' => 'OUTSTANDING', 'value' => 327500.0, 'accent' => true]],
            'note' => '2 CUSTOMERS OWE 327,500',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function ledger(): array
    {
        return [
            'kind' => 'ledger',
            'key' => 'TrialBalance',
            'title' => 'Trial Balance',
            'subtitle' => 'Acme Ltd · as of 20 Feb 2027',
            'columns' => ['Account', 'Debit', 'Credit'],
            'numeric' => [1, 2],
            'sections' => [
                [
                    'label' => 'ASSET',
                    'rows' => [
                        ['code' => '1000', 'cells' => ['Cash', '150,000', '']],
                        ['code' => '1100', 'cells' => ['Receivables', '275,000', '']],
                    ],
                    'total' => ['cells' => ['Total asset', '425,000', '0']],
                ],
                [
                    'label' => 'LIABILITY',
                    'rows' => [
                        ['code' => '2000', 'cells' => ['Payables', '', '425,000']],
                    ],
                    'total' => ['cells' => ['Total liability', '0', '425,000']],
                ],
            ],
            'tiles' => [],
            'note' => 'BALANCED · DEBITS = CREDITS',
        ];
    }

    /** @return array<string, mixed> */
    private function statement(bool $comparison = true): array
    {
        return [
            'kind' => 'statement',
            'key' => 'BalanceSheet',
            'title' => 'Balance Sheet',
            'subtitle' => 'Acme Ltd · as of 20 Feb 2027',
            'current_label' => '20 Feb 2027',
            'previous_label' => $comparison ? '20 Feb 2026' : null,
            'sections' => [
                [
                    'label' => 'ASSETS',
                    'rows' => [
                        ['code' => '1000', 'label' => 'Cash', 'current' => 150000.0, 'previous' => 90000.0],
                        ['code' => '1100', 'label' => 'Receivables', 'current' => 275000.0, 'previous' => null],
                    ],
                    'total' => ['label' => 'Total assets', 'current' => 425000.0, 'previous' => 90000.0],
                ],
            ],
            'closing' => ['label' => 'Net assets', 'current' => 425000.0, 'previous' => 90000.0],
            'tiles' => [],
            'note' => 'BALANCED',
        ];
    }

    // ──────────────────────────────────── one grid from three shapes ──

    /** A table is already a grid, and is passed through as the pane drew it. */
    public function test_a_table_becomes_a_grid_unchanged(): void
    {
        $grid = $this->export->grid($this->table());

        $this->assertSame(['Customer', 'Current', 'Overdue'], $grid['columns']);
        $this->assertSame(['Karachi Textiles', '275,000', '—'], $grid['rows'][0]);
        $this->assertSame(['Total — 2 customers', '315,000', '12,500'], $grid['footer']);
        $this->assertSame([], $grid['sections'], 'a table has no headings inside it');
    }

    /**
     * A ledger's sections become rows, with the label in the first column.
     *
     * The only thing a flat grid can do with a heading, and how a printed ledger has always looked.
     */
    public function test_a_ledger_flattens_its_sections_into_rows(): void
    {
        $grid = $this->export->grid($this->ledger());

        $this->assertSame(['Account', 'Debit', 'Credit'], $grid['columns']);
        $this->assertSame(['ASSET', '', ''], $grid['rows'][0]);
        $this->assertSame(['Cash', '150,000', ''], $grid['rows'][1]);
        $this->assertSame(['Total asset', '425,000', '0'], $grid['rows'][3]);
        $this->assertSame(['LIABILITY', '', ''], $grid['rows'][4]);
        $this->assertCount(7, $grid['rows']);
    }

    /**
     * Which rows are headings is reported, not inferred.
     *
     * A one-filled-cell row is a plausible way to guess and a wrong one: a genuinely single-column report
     * would have every row read as a heading.
     */
    public function test_the_grid_says_which_rows_are_headings(): void
    {
        $grid = $this->export->grid($this->ledger());

        $this->assertSame([0, 4], $grid['sections']);
    }

    /** A statement's columns are the two period labels, with the label column unnamed. */
    public function test_a_statement_becomes_label_and_period_columns(): void
    {
        $grid = $this->export->grid($this->statement());

        $this->assertSame(['', '20 Feb 2027', '20 Feb 2026'], $grid['columns']);
        $this->assertSame(['ASSETS', '', ''], $grid['rows'][0]);
        $this->assertSame(['Cash', '150,000', '90,000'], $grid['rows'][1]);
        $this->assertSame(['Total assets', '425,000', '90,000'], $grid['rows'][3]);
    }

    /** Without a comparison there is no prior-year column at all, rather than an empty one. */
    public function test_a_statement_without_a_comparison_has_two_columns(): void
    {
        $grid = $this->export->grid($this->statement(comparison: false));

        $this->assertSame(['', '20 Feb 2027'], $grid['columns']);
        $this->assertSame(['Cash', '150,000'], $grid['rows'][1]);
        $this->assertSame([1], $grid['numeric']);
    }

    /**
     * A missing prior-year figure is an empty cell, not a nought.
     *
     * An account that did not exist last year is a different thing from one that was empty, and a nought
     * would claim the second.
     */
    public function test_a_missing_prior_year_figure_is_empty_not_nought(): void
    {
        $grid = $this->export->grid($this->statement());

        $this->assertSame(['Receivables', '275,000', ''], $grid['rows'][2]);
    }

    /**
     * The closing line is the footer, outside every section.
     *
     * A statement's bottom line belongs to the statement and not to whichever section happened to be last.
     */
    public function test_the_closing_line_becomes_the_footer(): void
    {
        $grid = $this->export->grid($this->statement());

        $this->assertSame(['Net assets', '425,000', '90,000'], $grid['footer']);
    }

    // ─────────────────────────── what cannot be exported ──

    /**
     * A `file` report cannot be exported, because it already is one.
     *
     * The salary bank file, the FBR tax file and the bank payment file: each screen describes a download
     * rather than containing it, so a CSV of that screen would be a CSV about a file.
     */
    public function test_a_file_report_is_not_exportable(): void
    {
        // The real shape — tiles, a note and a count, with no columns and no rows, which is what
        // `ReportPane::file()` returns. Worth stating because it means the kind check and the empty-rows
        // check both refuse this payload and no test can tell which one did. The exporter's docblock says
        // the same rather than implying the constant is load-bearing.
        $this->assertFalse($this->export->supports([
            'kind' => 'file',
            'title' => 'Bank Payment File',
            'tiles' => [['label' => 'PAYMENTS', 'value' => 12.0, 'accent' => false]],
            'note' => 'THE FILE WOULD CARRY 12 PAYMENTS',
            'rows_count' => 12,
        ]));
    }

    /** Nothing selected is nothing to export. */
    public function test_nothing_is_not_exportable(): void
    {
        $this->assertFalse($this->export->supports(null));
    }

    /** Nor is a report with no rows — an empty period produces an empty file nobody wanted. */
    public function test_an_empty_report_is_not_exportable(): void
    {
        $this->assertFalse($this->export->supports($this->table(['rows' => [], 'footer' => null])));
    }

    /** A report with rows is. */
    public function test_a_report_with_rows_is_exportable(): void
    {
        $this->assertTrue($this->export->supports($this->table()));
        $this->assertTrue($this->export->supports($this->ledger()));
        $this->assertTrue($this->export->supports($this->statement()));
    }

    // ─────────────────── undoing the formatting, which is the point ──

    /**
     * Thousands separators come off the numeric columns.
     *
     * `275,000` in a CSV arrives as text in most importers, or splits across two cells in the worst of them.
     * A column somebody exported in order to sum it has to arrive as numbers.
     */
    public function test_the_csv_strips_thousands_separators_from_numeric_columns(): void
    {
        $csv = $this->export->csv($this->table());

        $this->assertStringContainsString('"Karachi Textiles",275000,', $csv);
        $this->assertStringNotContainsString('275,000', $csv);
    }

    /**
     * An em dash becomes an empty cell.
     *
     * A dash means the value does not apply. Passed through it arrives as text and stops the column summing;
     * as a nought it would claim a figure that was never there. Empty is what a spreadsheet uses for
     * "nothing here".
     */
    public function test_the_csv_empties_dashes_in_numeric_columns(): void
    {
        $csv = $this->export->csv($this->table());

        // Asserted on the row rather than on the whole file, because a dash is only wrong in a *numeric*
        // cell. The footer's "Total — 2 customers" is prose and keeps its em dash, and a blanket
        // assertion that no dash survives anywhere would have demanded the wrong behaviour.
        $this->assertStringContainsString("\"Karachi Textiles\",275000,\n", $csv);
        $this->assertStringNotContainsString('275000,—', $csv);
    }

    /** And a dash in a text cell is prose, which survives untouched. */
    public function test_a_dash_in_a_text_cell_survives(): void
    {
        $this->assertStringContainsString('"Total — 2 customers"', $this->export->csv($this->table()));
    }

    /** A text column keeps its text, dashes and all — only the numeric columns are unformatted. */
    public function test_a_text_column_is_left_alone(): void
    {
        $csv = $this->export->csv($this->table([
            'columns' => ['Customer', 'Standing', 'Overdue'],
            'numeric' => [2],
            'rows' => [['Karachi Textiles', 'Within 180 days', '12,500']],
            'footer' => null,
        ]));

        $this->assertStringContainsString('Within 180 days', $csv);
        $this->assertStringContainsString(',12500', $csv);
    }

    /**
     * A lone dash in a *text* column is kept, because there it is a value rather than a missing figure.
     *
     * The serial number a phone does not have, the source nobody recorded. In a numeric column a dash means
     * the figure does not apply and an empty cell says that properly; in a text column it is the pane's own
     * wording, and unformatting every column alike would delete it.
     */
    public function test_a_lone_dash_in_a_text_column_is_kept(): void
    {
        $csv = $this->export->csv($this->table([
            'columns' => ['Item', 'Serial', 'Value'],
            'numeric' => [2],
            'rows' => [['Laptop', '—', '180,000']],
            'footer' => null,
        ]));

        $this->assertStringContainsString('Laptop,—,180000', $csv);
    }

    /**
     * A percentage keeps its sign rather than becoming a bare number.
     *
     * Dropping the `%` would silently turn a proportion into a count — 75% into 75 — so the cell stays text.
     * That is the honest outcome and the help says so.
     */
    public function test_a_percentage_keeps_its_symbol(): void
    {
        $csv = $this->export->csv($this->table([
            'columns' => ['Environment', 'Uptime'],
            'numeric' => [1],
            'rows' => [['Production', '99.95%']],
            'footer' => null,
        ]));

        $this->assertStringContainsString('99.95%', $csv);
    }

    /** A negative figure survives with its sign, which is the whole difficulty of escaping formulas. */
    public function test_a_negative_figure_keeps_its_sign_and_is_not_escaped(): void
    {
        $csv = $this->export->csv($this->table([
            'columns' => ['Employee', 'Net'],
            'numeric' => [1],
            'rows' => [['Danish Test', '-60,000']],
            'footer' => null,
        ]));

        $this->assertStringContainsString('"Danish Test",-60000', $csv);
        $this->assertStringNotContainsString("'-", $csv);
    }

    // ──────────────────────────── formula injection ──

    /**
     * A cell beginning `=` is escaped, because a spreadsheet would run it.
     *
     * This is an attack on whoever opens the export rather than on this application: report cells carry text
     * somebody typed — a project name, a checklist item, a delivery failure reason — and every one of those
     * reaches a CSV unchanged unless something stops it.
     */
    public function test_a_formula_in_a_text_cell_is_escaped(): void
    {
        $csv = $this->export->csv($this->table([
            'columns' => ['Project', 'Cost'],
            'numeric' => [1],
            'rows' => [['=1+1', '100']],
            'footer' => null,
        ]));

        $this->assertStringContainsString("'=1+1", $csv);
    }

    /** All four of the prefixes a spreadsheet treats as a formula. */
    public function test_every_formula_prefix_is_escaped(): void
    {
        $csv = $this->export->csv($this->table([
            'columns' => ['Project'],
            'numeric' => [],
            'rows' => [['=cmd'], ['+cmd'], ['@SUM(A1)'], ['-cmd']],
            'footer' => null,
        ]));

        foreach (["'=cmd", "'+cmd", "'@SUM(A1)", "'-cmd"] as $escaped) {
            $this->assertStringContainsString($escaped, $csv);
        }
    }

    /** Ordinary text is not escaped — the guard has to be invisible when it is not needed. */
    public function test_ordinary_text_is_not_escaped(): void
    {
        $csv = $this->export->csv($this->table([
            'columns' => ['Project'],
            'numeric' => [],
            'rows' => [['Acme Portal']],
            'footer' => null,
        ]));

        $this->assertStringContainsString('Acme Portal', $csv);
        $this->assertStringNotContainsString("'Acme", $csv);
    }

    /** A column header could carry the same payload, so it is escaped too. */
    public function test_a_column_header_is_escaped(): void
    {
        $csv = $this->export->csv($this->table([
            'columns' => ['=danger', 'Cost'],
            'numeric' => [1],
            'rows' => [['Acme', '100']],
            'footer' => null,
        ]));

        $this->assertStringContainsString("'=danger", $csv);
    }

    // ────────────────────────────── what the file says ──

    /** The file names the report and states whose figures at what date. */
    public function test_the_csv_carries_the_title_and_subtitle(): void
    {
        $csv = $this->export->csv($this->table());
        $lines = explode("\n", $csv);

        $this->assertStringContainsString('Aged Receivables', $lines[0]);
        $this->assertStringContainsString('Acme Ltd', $lines[1]);
    }

    /** And the report's own summary, last. */
    public function test_the_csv_carries_the_note(): void
    {
        $this->assertStringContainsString('2 CUSTOMERS OWE 327,500', $this->export->csv($this->table()));
    }

    /** The footer is written as a row, unformatted like the rest. */
    public function test_the_csv_carries_the_footer(): void
    {
        $this->assertStringContainsString('"Total — 2 customers",315000,12500', $this->export->csv($this->table()));
    }

    /** A ledger's flattened sections reach the file. */
    public function test_a_ledger_exports_its_sections(): void
    {
        $csv = $this->export->csv($this->ledger());

        $this->assertStringContainsString('ASSET,,', $csv);
        $this->assertStringContainsString('Cash,150000,', $csv);
        $this->assertStringContainsString('"Total asset",425000,0', $csv);
    }

    /** A statement's raw amounts arrive as numbers, not as formatted strings. */
    public function test_a_statement_exports_bare_numbers(): void
    {
        $csv = $this->export->csv($this->statement());

        $this->assertStringContainsString('Cash,150000,90000', $csv);
        $this->assertStringContainsString('"Net assets",425000,90000', $csv);
    }

    // ─────────────────────────────────── the filename ──

    /** The report and the date, because two exports of one report at two dates is the commonest pair. */
    public function test_the_filename_names_the_report_and_the_date(): void
    {
        $this->assertSame(
            'aged-receivables-2027-02-20.csv',
            $this->export->filename($this->table(), '2027-02-20', 'csv'),
        );
    }

    /** The extension is normalised whether or not a dot was passed. */
    public function test_the_filename_takes_an_extension_with_or_without_a_dot(): void
    {
        $this->assertSame(
            'aged-receivables-2027-02-20.pdf',
            $this->export->filename($this->table(), '2027-02-20', '.pdf'),
        );
    }

    /** A title with punctuation still makes a filename. */
    public function test_a_punctuated_title_is_slugged(): void
    {
        $this->assertSame(
            'environment-health-incidents-2027-02-20.csv',
            $this->export->filename(
                $this->table(['title' => 'Environment Health & Incidents']),
                '2027-02-20',
                'csv',
            ),
        );
    }
}
