<?php

namespace App\Filament\Support;

use App\Models\CustomField;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;

/**
 * Builds Filament form/table components from a model's custom field definitions.
 * Form fields live under the `custom_fields` state path and are persisted by the
 * InteractsWithCustomFields page trait (not as model columns).
 */
class CustomFieldsSchema
{
    /**
     * A "Custom Fields" form section, or [] if the model has none.
     *
     * @return array<int, Section>
     */
    public static function form(string $model): array
    {
        $fields = CustomField::query()->forModel($model)->get();

        if ($fields->isEmpty()) {
            return [];
        }

        $components = $fields->map(fn (CustomField $field) => self::formComponent($field))->all();

        return [
            Section::make('Custom Fields')
                ->statePath('custom_fields')
                ->columns(2)
                ->schema($components),
        ];
    }

    protected static function formComponent(CustomField $field)
    {
        $component = match ($field->type) {
            'textarea' => Textarea::make($field->code),
            'number' => TextInput::make($field->code)->numeric(),
            'date' => DatePicker::make($field->code),
            'boolean' => Toggle::make($field->code),
            'select' => Select::make($field->code)
                ->options(collect($field->options ?? [])->mapWithKeys(fn ($o) => [$o => $o])->all())
                ->native(false),
            default => TextInput::make($field->code)->maxLength(255),
        };

        $component = $component
            ->label($field->name)
            ->helperText($field->help)
            ->required($field->is_required)
            ->dehydrated(false); // persisted via the page trait, not the model

        // Per-field validation: min/max as numeric bounds (number) or length (text), plus regex.
        if ($field->min !== null) {
            $field->type === 'number'
                ? $component->minValue((float) $field->min)
                : $component->minLength((int) $field->min);
        }
        if ($field->max !== null) {
            $field->type === 'number'
                ? $component->maxValue((float) $field->max)
                : $component->maxLength((int) $field->max);
        }
        if ($field->regex && method_exists($component, 'rule')) {
            $component->rule('regex:/'.$field->regex.'/');
        }

        return $component;
    }

    /**
     * Infolist entries for a model's custom fields.
     *
     * @return array<int, \Filament\Infolists\Components\TextEntry|\Filament\Infolists\Components\IconEntry>
     */
    public static function infolistEntries(string $model): array
    {
        return CustomField::query()->forModel($model)->get()
            ->map(function (CustomField $field) {
                $resolve = fn ($record) => $record->customFieldsData()[$field->code] ?? null;

                if ($field->type === 'boolean') {
                    return \Filament\Infolists\Components\IconEntry::make('cf_'.$field->code)
                        ->label($field->name)
                        ->boolean()
                        ->state(fn ($record) => (bool) $resolve($record));
                }

                return \Filament\Infolists\Components\TextEntry::make('cf_'.$field->code)
                    ->label($field->name)
                    ->state($resolve);
            })
            ->all();
    }

    /**
     * Toggleable table columns for a model's custom fields (hidden by default).
     *
     * @return array<int, TextColumn|IconColumn>
     */
    public static function tableColumns(string $model): array
    {
        return CustomField::query()->forModel($model)->get()
            ->map(function (CustomField $field) {
                $key = 'cf_'.$field->code;

                if ($field->type === 'boolean') {
                    return IconColumn::make($key)
                        ->label($field->name)
                        ->boolean()
                        ->state(fn ($record) => (bool) ($record->customFieldsData()[$field->code] ?? false))
                        ->toggleable(isToggledHiddenByDefault: true);
                }

                return TextColumn::make($key)
                    ->label($field->name)
                    ->state(fn ($record) => $record->customFieldsData()[$field->code] ?? null)
                    ->toggleable(isToggledHiddenByDefault: true);
            })
            ->all();
    }
}
