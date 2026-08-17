<?php

namespace App\Support\Contracts;

use Illuminate\Support\Collection;

/**
 * One thing a company can import from a spreadsheet at setup.
 *
 * `Core\Services\CsvImportService` held all three imports itself, which meant Core naming `Contact`,
 * `Product`, `Account`, `JournalEntry` and `JournalEntryService` — five classes across three modules, for a
 * screen that is otherwise pure CSV mechanics. See docs/module-packaging-plan.md §9.
 *
 * The split is between *reading* and *writing*. Finding columns by header, numbering lines, skipping blanks,
 * previewing without writing and rendering a template are the same work whatever is being imported, and stay
 * in Core. What a row means, when a row is unusable, and what gets written are the owning module's, and come
 * from here.
 *
 * §9 called this a `CsvImporterRegistry` and noted it "gains a real feature": the import type list is
 * whatever is registered, so a module that ships an importer appears in the dropdown without Core being
 * told, and one that is not installed cannot be picked. `App\Support\CsvImporters` is the registry.
 */
interface CsvImporter
{
    /** The import type as it appears in the URL, the template filename and the form state. */
    public function key(): string;

    /** What the person choosing an import type reads, e.g. "Clients and suppliers". */
    public function label(): string;

    /**
     * The columns this import expects, in order, and by the names it looks for in the header.
     *
     * The **first** column is the required one: a file without it is an error about the file rather than
     * about any row, because a spreadsheet missing its key column is the wrong file.
     *
     * @return array<int, string>
     */
    public function columns(): array;

    /**
     * One filled-in row for the downloadable template, in the same order as `columns()`.
     *
     * It has to be a row this importer accepts — a template that fails its own preview is worse than none,
     * which is what `CsvImportTest` asserts for every registered type.
     *
     * @return array<int, string>
     */
    public function example(): array;

    /**
     * Why this row cannot be used, or null.
     *
     * Phrased to complete "Line 12: …", so "no name" and not "The name is missing". Returning a reason skips
     * the row; it never aborts the import.
     *
     * @param  array<string, string>  $row  keyed by `columns()`, plus `_line`
     */
    public function problemWith(array $row): ?string;

    /**
     * Write the usable rows and return how many were written.
     *
     * Rows here have already passed `problemWith()`. Imports are expected to be re-runnable — running the
     * same file twice should correct rather than duplicate — because somebody fixing three rows re-uploads
     * the whole file.
     *
     * `$date` is whatever was entered in the field this importer asked for in `dateField()`, and is null for
     * the importers that asked for none.
     *
     * @param  Collection<int, array<string, string>>  $rows
     */
    public function write(Collection $rows, ?string $date = null): int;

    /**
     * The "as at" date field this import needs, or null if it needs none.
     *
     * Only dated imports have one — an opening trial balance is a single fact about a single date — and the
     * label and help text are the module's domain knowledge, not the page's. When this returns null the
     * field is hidden, which is how the page stopped asking whether the chosen type was opening balances.
     *
     * @return null|array{label: string, help: string}
     */
    public function dateField(): ?array;
}
