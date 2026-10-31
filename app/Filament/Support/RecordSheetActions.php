<?php

namespace App\Filament\Support;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Turns a list's view and edit actions into the Material side sheet — docs/side-sheets.md.
 *
 * The sheet is Filament's own slide-over modal, so the form, validation, authorisation and saving are
 * Filament's EditAction and ViewAction unchanged; this only dresses it:
 *
 *  - the header carries the record's title, a status badge when the record has one, and the sheet's
 *    tools — copy a link that reopens it, open the full page — beside Filament's close button;
 *  - the header and the Save / Cancel footer stay put while the body scrolls;
 *  - an edit sheet does not close on a click outside it, where one stray click would lose the typing,
 *    and it keeps Filament's unsaved-changes alert;
 *  - on a phone the sheet fills the screen (material.css).
 */
final class RecordSheetActions
{
    /**
     * @param  class-string<resource>  $resource
     */
    public static function configure(Action $action, string $resource, RecordSheet $sheet): void
    {
        $isEdit = $action instanceof EditAction;
        $name = $action->getName() ?? ($isEdit ? 'edit' : 'view');

        $action
            ->slideOver()
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            // Material puts a sheet's actions at its end edge, the confirming one last.
            ->modalFooterActionsAlignment(Alignment::End)
            ->extraModalWindowAttributes(['class' => 'fi-record-sheet'])
            ->modalHeading(fn (Model $record): HtmlString => self::heading($resource, $record))
            ->modalDescription(fn (Model $record): HtmlString => self::tools($resource, $record, $name));

        if ($isEdit) {
            $action
                ->closeModalByClickingAway(false)
                ->unsavedChangesAlert();

            return;
        }

        /** @var ViewAction $action */
        // Custom field values are read by the edit page's own hook, which a sheet does not run.
        $action->mutateRecordDataUsing(fn (array $data, Model $record): array => method_exists($record, 'customFieldsData')
            ? [...$data, 'custom_fields' => $record->customFieldsData()]
            : $data);

        // Viewing in the sheet with editing on the full page: the way there sits where Save would.
        if ($sheet === RecordSheet::SheetView && $resource::hasPage('edit')) {
            $action->extraModalFooterActions(fn (Model $record): array => [
                Action::make('editFullPage')
                    ->label(__('Edit'))
                    ->url($resource::getUrl('edit', ['record' => $record]))
                    ->visible($resource::canEdit($record)),
            ]);
        }
    }

    /**
     * The link that reopens this sheet: the list, with Filament's own table-action URL parameters.
     *
     * @param  class-string<resource>  $resource
     */
    public static function link(string $resource, Model $record, string $actionName): string
    {
        return $resource::getUrl('index', [
            'tableAction' => $actionName,
            'tableActionRecord' => $record->getKey(),
        ]);
    }

    /**
     * The full page the sheet stands in for: the view page when there is one, otherwise the edit page.
     *
     * @param  class-string<resource>  $resource
     */
    public static function fullPageUrl(string $resource, Model $record): ?string
    {
        foreach (['view', 'edit'] as $page) {
            if ($resource::hasPage($page) && $resource::{'can'.ucfirst($page)}($record)) {
                return $resource::getUrl($page, ['record' => $record]);
            }
        }

        return null;
    }

    /**
     * @param  class-string<resource>  $resource
     */
    private static function heading(string $resource, Model $record): HtmlString
    {
        return new HtmlString(view('filament.record-sheet.heading', [
            'title' => (string) ($resource::getRecordTitle($record) ?? $resource::getModelLabel()),
            'badge' => self::badge($record),
        ])->render());
    }

    /**
     * @param  class-string<resource>  $resource
     */
    private static function tools(string $resource, Model $record, string $actionName): HtmlString
    {
        return new HtmlString(view('filament.record-sheet.tools', [
            'link' => self::link($resource, $record, $actionName),
            'fullPageUrl' => self::fullPageUrl($resource, $record),
        ])->render());
    }

    /**
     * The record's state as a badge: its `status` when it has one, otherwise active or inactive.
     *
     * @return array{label: string, tone: string}|null
     */
    public static function badge(Model $record): ?array
    {
        $attributes = $record->getAttributes();

        if (array_key_exists('status', $attributes) && filled($attributes['status'])) {
            $status = $record->getAttribute('status');

            $label = match (true) {
                $status instanceof HasLabel => (string) $status->getLabel(),
                $status instanceof BackedEnum => Str::headline((string) $status->value),
                $status instanceof UnitEnum => Str::headline($status->name),
                default => Str::headline((string) $status),
            };

            $color = $status instanceof HasColor ? $status->getColor() : null;

            return ['label' => $label, 'tone' => is_string($color) ? $color : 'gray'];
        }

        if (array_key_exists('is_active', $attributes) && $attributes['is_active'] !== null) {
            return $record->getAttribute('is_active')
                ? ['label' => __('Active'), 'tone' => 'success']
                : ['label' => __('Inactive'), 'tone' => 'gray'];
        }

        return null;
    }
}
