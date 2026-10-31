<?php

namespace App\Filament\Support;

use Closure;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/**
 * The resource's own edit page, run behind the edit sheet — docs/side-sheets.md.
 *
 * An edit sheet is Filament's EditAction on the list page, so on its own it skips whatever the
 * resource's edit page does around a save: reshaping data before the form is filled or before it is
 * saved, saving through a service instead of `update()`, and work after the save, custom field values
 * among it ({@see \App\Filament\Concerns\InteractsWithCustomFields}). This runs those methods on an
 * instance of that page, so the sheet and the page save through the same code — one code path, not a
 * copy of each hook.
 *
 * The page is never mounted or rendered: it is given the record, and for the save the form's raw state
 * as `$data`, which is all its hooks read. The hooks it runs, in the page's own order:
 *
 *  - filling: `mutateFormDataBeforeFill()`;
 *  - saving: `beforeSave`, `mutateFormDataBeforeSave()`, `handleRecordUpdate()`, `afterSave` —
 *    `before…`/`after…` through the page's `callHook()`, so trait hooks (`afterSaveSomeTrait()`) run
 *    as they do on the page.
 *
 * A page whose hooks need more than that — its form, a redirect, other page state — is not one a sheet
 * can stand in for; `RecordSheetCoverageTest` keeps such resources off `Sheet`.
 */
final class RecordSheetEditPage
{
    /** The page methods this runs; anything else an edit page overrides keeps it off `Sheet`. */
    public const HOOKS = [
        'mutateFormDataBeforeFill',
        'mutateFormDataBeforeSave',
        'handleRecordUpdate',
        'beforeSave',
        'afterSave',
    ];

    /**
     * @param  class-string<resource>  $resource
     * @return class-string<EditRecord>|null
     */
    public static function pageFor(string $resource): ?string
    {
        if (! $resource::hasPage('edit')) {
            return null;
        }

        $page = $resource::getPages()['edit']->getPage();

        return is_subclass_of($page, EditRecord::class) ? $page : null;
    }

    /**
     * The record's attributes as the edit page would fill its form with them.
     *
     * @param  class-string<EditRecord>  $page
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fill(string $page, Model $record, array $data): array
    {
        return self::run($page, $record, [], fn (): array => $this->mutateFormDataBeforeFill($data));
    }

    /**
     * Save as the edit page saves: its hooks around its own update.
     *
     * @param  class-string<EditRecord>  $page
     * @param  array<string, mixed>  $data  the validated, dehydrated form state
     * @param  array<string, mixed>  $rawData  the form's raw state, which the page knows as `$data`
     */
    public static function save(string $page, Model $record, array $data, array $rawData): Model
    {
        return self::run($page, $record, $rawData, function () use ($record, $data): Model {
            $this->callHook('beforeSave');

            $data = $this->mutateFormDataBeforeSave($data);

            $this->record = $this->handleRecordUpdate($record, $data);

            $this->callHook('afterSave');

            return $this->record;
        });
    }

    /**
     * @param  class-string<EditRecord>  $page
     * @param  array<string, mixed>  $rawData
     */
    private static function run(string $page, Model $record, array $rawData, Closure $work): mixed
    {
        /** @var EditRecord $instance */
        $instance = app($page);
        $instance->record = $record;
        $instance->data = $rawData;

        // Bound to the page, so its protected hooks are callable as the page calls them.
        return Closure::bind($work, $instance, $page)();
    }
}
