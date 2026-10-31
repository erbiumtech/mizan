<?php

namespace App\Filament\Support;

use ReflectionClass;

/**
 * Where a resource's records open from its list — docs/side-sheets.md.
 *
 * Every resource declares one, as `protected static RecordSheet $recordSheet`, and
 * `RecordSheetCoverageTest` fails the day one does not, so a new resource makes the choice on purpose:
 *
 *  - `Sheet`: master data. View and edit open in a Material side sheet over the list, which keeps its
 *    filters, scroll and page. Only for a resource whose edit page adds nothing to Filament's save — no
 *    lifecycle hooks, no custom fields, no relation managers, no repeaters — because the sheet runs
 *    Filament's own edit action, not that page.
 *  - `SheetView`: viewing opens in the sheet; editing stays on the full page, which the sheet links to.
 *  - `Page`: everything stays on full pages — documents with lines, relation managers or edit logic.
 *
 * The full pages stay registered whatever the choice, so links, bookmarks and tests keep working; the
 * list page carries {@see \App\Filament\Concerns\OpensRecordsInSheets} to act on the declaration.
 */
enum RecordSheet: string
{
    case Sheet = 'sheet';
    case SheetView = 'sheet-view';
    case Page = 'page';

    /**
     * The resource's declaration, or `Page` when it has none (the coverage test is what insists).
     *
     * @param  class-string  $resource
     */
    public static function of(string $resource): self
    {
        $class = new ReflectionClass($resource);

        if (! $class->hasProperty('recordSheet')) {
            return self::Page;
        }

        $value = $class->getProperty('recordSheet')->getValue();

        return $value instanceof self ? $value : self::Page;
    }

    public function viewsInSheet(): bool
    {
        return $this !== self::Page;
    }

    public function editsInSheet(): bool
    {
        return $this === self::Sheet;
    }
}
