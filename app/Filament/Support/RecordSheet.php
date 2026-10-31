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
 *    filters, scroll and page. The sheet saves through the edit page's own hooks
 *    ({@see RecordSheetEditPage}), so only for a resource whose edit page does nothing else around a
 *    save, with no relation managers and no repeaters.
 *  - `SheetView`: medium records. Viewing opens in the sheet; editing stays on the full page, which
 *    the sheet links to.
 *  - `Page`: everything stays on full pages — documents with lines, tabs or their own actions.
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
