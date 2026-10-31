<?php

namespace App\Filament\Concerns;

use App\Filament\Support\RecordSheet;
use App\Filament\Support\RecordSheetActions;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A list page that opens its records in the Material side sheet — docs/side-sheets.md.
 *
 * Acts on the resource's {@see RecordSheet} declaration, so the same trait serves `Sheet` and
 * `SheetView`, and a resource that goes back to `Page` needs no change here. Used only on the list
 * page of a resource that declares a sheet; `RecordSheetCoverageTest` holds the two together.
 *
 * Three things change, and nothing else:
 *
 *  1. Filament gives a resource's view and edit actions the URL of their page whenever the page
 *     exists. {@see getDefaultActionUrl()} withholds it for the ones that open in the sheet, which is
 *     all it takes for Filament to open them as a modal over the list instead.
 *  2. The row stops being a link to the page and runs the sheet's action instead — view first, then
 *     edit, Filament's own order. A `Sheet` list without a view action gains one that shows only to
 *     someone who may not edit, so a read-only user still gets the sheet rather than a dead row.
 *  3. The open sheet is written into Filament's own `?tableAction=…&tableActionRecord=…` parameters,
 *     which Filament already reads on load, so a refresh or a copied address reopens it. The sheet at
 *     the bottom of the action stack decides, so a confirmation opened inside it keeps the address.
 */
trait OpensRecordsInSheets
{
    public function getDefaultActionUrl(Action $action): ?string
    {
        if ($this->opensInSheet($action)) {
            return null;
        }

        return parent::getDefaultActionUrl($action);
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        $resource = static::getResource();
        $sheet = RecordSheet::of($resource);

        if (! $sheet->viewsInSheet()) {
            return $table;
        }

        if (! $table->getAction('view')) {
            $view = ViewAction::make();

            if ($sheet === RecordSheet::Sheet) {
                $view->visible(fn (Model $record): bool => ! $resource::canEdit($record));
            }

            $table->recordActions([$view, ...$table->getRecordActions()]);
        }

        foreach (['view', 'edit'] as $name) {
            $action = $table->getAction($name);

            if ($action && $this->opensInSheet($action)) {
                RecordSheetActions::configure($action, $resource, $sheet);
            }
        }

        // The row runs the sheet's action (Filament's recordAction) instead of linking to the page.
        return $table->recordUrl(null);
    }

    /**
     * Mounting is what opens a sheet, so the address follows it here.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     */
    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        $result = parent::mountAction($name, $arguments, $context);

        $this->syncSheetAddress();

        return $result;
    }

    /**
     * Every way a sheet closes — Save, Cancel, the close button, Escape — ends in this.
     */
    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        parent::unmountAction($cancelParentActions);

        $this->syncSheetAddress();
    }

    /**
     * Write the open sheet into Filament's `?tableAction=` parameters, or clear them once it closes.
     *
     * They are `#[Url]` properties, so Livewire carries the change into the address bar.
     */
    protected function syncSheetAddress(): void
    {
        $mounted = $this->mountedActions ?? [];
        $top = $mounted[0] ?? null;

        if (
            $top
            && ($top['context']['table'] ?? false)
            && filled($top['context']['recordKey'] ?? null)
            && in_array($top['name'] ?? null, ['view', 'edit'], true)
            && ($action = $this->getTable()->getAction($top['name']))
            && $this->opensInSheet($action)
        ) {
            $this->defaultTableAction = $top['name'];
            $this->defaultTableActionRecord = (string) $top['context']['recordKey'];

            return;
        }

        if (in_array($this->defaultTableAction, ['view', 'edit'], true)) {
            // Empty arrays, as Filament clears its own: `null` would reach the address as 'null'.
            $this->defaultTableAction = [];
            $this->defaultTableActionRecord = [];
        }
    }

    protected function opensInSheet(Action $action): bool
    {
        $resource = static::getResource();
        $model = $action->getModel();

        if (filled($model) && $model !== $resource::getModel()) {
            return false;
        }

        $sheet = RecordSheet::of($resource);

        return match (true) {
            $action instanceof EditAction => $sheet->editsInSheet(),
            $action instanceof ViewAction => $sheet->viewsInSheet(),
            default => false,
        };
    }
}
