<?php

namespace App\Modules\Core\Services;

use App\Support\Contracts\CsvImporter;
use App\Support\CsvImporters;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Getting a company's existing records in at setup.
 *
 * The GnuCash importer is the hard version of this — a whole book, with accounts and
 * history — and nobody setting up needs it. What they have is a spreadsheet of clients,
 * a spreadsheet of products, and a trial balance from their old system.
 *
 * Every import is checked before anything is written, and reports what it would do row
 * by row: an import that half-succeeds and stops leaves somebody guessing which half.
 * Rows that cannot be used are named with their line number and skipped, rather than
 * aborting the rest — a typo on line 40 should not cost the other 39.
 *
 * **What is here is the reading; what a file means comes from its module.** This class held all three
 * imports and so named `Contact`, `Product`, `Account`, `JournalEntry` and `JournalEntryService` — Core
 * depending on Invoicing, Inventory and Accounting for a screen that is otherwise CSV mechanics. Each import
 * is now an `App\Support\Contracts\CsvImporter` registered by its owning module; see `App\Support\CsvImporters`
 * and docs/module-packaging-plan.md §9. Finding columns by header, numbering lines, previewing without
 * writing and rendering a template are the same work whatever is being imported, and stayed.
 */
class CsvImportService
{
    /** The importer for a type, or an error naming the type rather than a null somewhere later. */
    public function importer(string $type): CsvImporter
    {
        return CsvImporters::get($type);
    }

    /**
     * The columns an import expects, in order.
     *
     * @return array<int, string>
     */
    public function columns(string $type): array
    {
        return $this->importer($type)->columns();
    }

    /**
     * Type => label, for the "what are you importing?" select.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return CsvImporters::labels();
    }

    /**
     * Read a CSV into rows keyed by the expected columns.
     *
     * The header is used to find the columns rather than assuming their order, because
     * a spreadsheet exported twice rarely has them in the same order. Extra columns are
     * ignored; a missing required one is an error about the file, not about a row.
     *
     * @return Collection<int, array<string, string>>
     */
    public function read(string $contents, string $type): Collection
    {
        $expected = $this->columns($type);

        $lines = preg_split('/\R/', trim($contents)) ?: [];

        if (count($lines) < 2) {
            throw new InvalidArgumentException('That file has a header and no rows.');
        }

        $header = array_map(
            fn (string $column): string => str_replace(' ', '_', strtolower(trim($column, " \t\"'"))),
            str_getcsv(array_shift($lines)),
        );

        $required = $expected[0];

        if (! in_array($required, $header, true)) {
            throw new InvalidArgumentException(
                "That file has no \"{$required}\" column. Expected: ".implode(', ', $expected).'.'
            );
        }

        $rows = collect();

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line);
            $row = ['_line' => $index + 2];

            foreach ($expected as $column) {
                $position = array_search($column, $header, true);
                $row[$column] = $position === false ? '' : trim((string) ($values[$position] ?? ''));
            }

            $rows->push($row);
        }

        return $rows;
    }

    /**
     * What an import would do, without doing it.
     *
     * @return array{rows: array<int, array<string, mixed>>, ready: int, skipped: int}
     */
    public function preview(string $contents, string $type): array
    {
        $importer = $this->importer($type);

        $rows = $this->read($contents, $type)
            ->map(fn (array $row): array => $row + ['_problem' => $importer->problemWith($row)])
            ->all();

        return [
            'rows' => $rows,
            'ready' => count(array_filter($rows, fn (array $row): bool => $row['_problem'] === null)),
            'skipped' => count(array_filter($rows, fn (array $row): bool => $row['_problem'] !== null)),
        ];
    }

    /**
     * @return array{imported: int, skipped: array<int, string>}
     */
    public function import(string $contents, string $type, ?string $date = null): array
    {
        $importer = $this->importer($type);
        $rows = $this->read($contents, $type);
        $skipped = [];
        $usable = collect();

        foreach ($rows as $row) {
            if ($problem = $importer->problemWith($row)) {
                $skipped[] = "Line {$row['_line']}: {$problem}";

                continue;
            }

            $usable->push($row);
        }

        return ['imported' => $importer->write($usable, $date), 'skipped' => $skipped];
    }

    /** A file somebody can fill in, rather than a format they have to guess. */
    public function template(string $type): string
    {
        $importer = $this->importer($type);

        return implode(',', $importer->columns())."\n".implode(',', $importer->example())."\n";
    }
}
