<?php

namespace App\Filament\Support;

use App\Modules\Core\Models\CustomField;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds Filament form/table components from a model's custom field definitions.
 * Form fields live under the `custom_fields` state path and are persisted by the
 * InteractsWithCustomFields page trait (not as model columns).
 */
class CustomFieldsSchema
{
    /**
     * Custom field form components, rendered inline alongside native fields
     * (nested under the `custom_fields` state path via dot-notation names).
     * Returns [] if the model has none.
     *
     * @return array<int, mixed>
     */
    public static function enabled(): bool
    {
        return (bool) config('custom_fields.enabled', true);
    }

    public static function form(string $model): array
    {
        if (! self::enabled()) {
            return [];
        }

        $fields = CustomField::query()->forModel($model)->get();

        // Fields that another field's visibility depends on must be live, or the
        // dependent field only appears on the next server round-trip.
        $controllers = $fields->pluck('visible_when_field')->filter()->flip();

        return $fields
            ->map(fn (CustomField $field) => self::formComponent($field, $controllers->has($field->code)))
            ->all();
    }

    protected static function formComponent(CustomField $field, bool $isController = false)
    {
        $name = 'custom_fields.'.$field->code;

        $options = fn () => collect($field->options ?? [])->mapWithKeys(fn ($o) => [$o => $o])->all();

        $component = match ($field->type) {
            'textarea' => Textarea::make($name),
            'number' => TextInput::make($name)->numeric(),
            'date' => DatePicker::make($name),
            'boolean' => Toggle::make($name),
            'select' => Select::make($name)->options($options())->native(false),
            'multi_select' => Select::make($name)->multiple()->options($options())->native(false),
            'color' => ColorPicker::make($name),
            'rich_text' => RichEditor::make($name),
            default => TextInput::make($name)->maxLength(255),
        };

        $component = $component
            ->label($field->name)
            ->helperText($field->help)
            ->required($field->is_required)
            ->dehydrated(false); // persisted via the page trait, not the model

        if ($field->placeholder && method_exists($component, 'placeholder')) {
            $component->placeholder($field->placeholder);
        }

        // Per-field validation: min/max as numeric bounds (number) or length (text), plus regex.
        // Guarded, so a min/max left over from a type change never calls a method the
        // component (select, color, rich text) doesn't have.
        if ($field->min !== null && method_exists($component, 'minLength')) {
            $field->type === 'number'
                ? $component->minValue((float) $field->min)
                : $component->minLength((int) $field->min);
        }
        if ($field->max !== null && method_exists($component, 'maxLength')) {
            $field->type === 'number'
                ? $component->maxValue((float) $field->max)
                : $component->maxLength((int) $field->max);
        }
        if ($field->regex && method_exists($component, 'rule')) {
            $component->rule('regex:/'.$field->regex.'/');
        }

        if ($isController) {
            $component->live();
        }

        // Conditional visibility: shown only while one sibling field equals one value.
        // ponytail: single field=value equality, no operators or and/or — a rule engine
        // only if a real definition ever needs one.
        if ($field->visible_when_field !== null) {
            $component->visible(fn (Get $get): bool => self::visibleWhenMatches($field, $get('custom_fields.'.$field->visible_when_field)));
        }

        return $component;
    }

    /** Whether the controlling field's current state matches the configured value. */
    protected static function visibleWhenMatches(CustomField $field, mixed $state): bool
    {
        if (is_array($state)) {
            return in_array($field->visible_when_value, $state, true);
        }

        if (is_bool($state)) {
            $state = $state ? '1' : '0';
        }

        return (string) $state === (string) $field->visible_when_value;
    }

    /**
     * Infolist entries for a model's custom fields.
     *
     * @return array<int, \Filament\Infolists\Components\TextEntry|\Filament\Infolists\Components\IconEntry>
     */
    public static function infolistEntries(string $model): array
    {
        if (! self::enabled()) {
            return [];
        }

        return CustomField::query()->forModel($model)->get()
            ->map(function (CustomField $field) {
                $resolve = fn ($record) => self::displayValue($field, $record->customFieldsData()[$field->code] ?? null);

                if ($field->type === 'boolean') {
                    return \Filament\Infolists\Components\IconEntry::make('cf_'.$field->code)
                        ->label($field->name)
                        ->boolean()
                        ->state(fn ($record) => (bool) ($record->customFieldsData()[$field->code] ?? false));
                }

                $entry = \Filament\Infolists\Components\TextEntry::make('cf_'.$field->code)
                    ->label($field->name)
                    ->state($resolve);

                if ($field->type === 'rich_text') {
                    $entry->html(); // Filament sanitizes via Str::sanitizeHtml() on output
                }

                return $entry;
            })
            ->all();
    }

    /** A stored value as a table/infolist string: arrays joined, rich text shown as HTML elsewhere. */
    protected static function displayValue(CustomField $field, mixed $value): mixed
    {
        return match (true) {
            $field->type === 'multi_select' && is_array($value) => implode(', ', $value),
            default => $value,
        };
    }

    /**
     * Toggleable table columns for a model's custom fields (hidden by default).
     *
     * @return array<int, TextColumn|IconColumn>
     */
    public static function tableColumns(string $model): array
    {
        if (! self::enabled()) {
            return [];
        }

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
                    // Rich text is stripped to plain text in cells — a table row is no place for markup.
                    ->state(fn ($record) => $field->type === 'rich_text'
                        ? str(strip_tags((string) ($record->customFieldsData()[$field->code] ?? '')))->limit(80)->toString()
                        : self::displayValue($field, $record->customFieldsData()[$field->code] ?? null))
                    ->toggleable(isToggledHiddenByDefault: true);
            })
            ->all();
    }

    /**
     * Table filters for the filterable custom field types (select, multi_select,
     * boolean), matching via whereJsonContains on custom_field_values.value.
     *
     * @return array<int, SelectFilter|TernaryFilter>
     */
    public static function tableFilters(string $model): array
    {
        if (! self::enabled()) {
            return [];
        }

        return CustomField::query()->forModel($model)->get()
            // ponytail: encrypted values are ciphertext at rest and can't be filtered;
            // a blind-index column is the upgrade path if anyone ever needs it.
            ->reject(fn (CustomField $field) => $field->is_encrypted)
            ->filter(fn (CustomField $field) => in_array($field->type, ['select', 'multi_select', 'boolean'], true))
            ->map(function (CustomField $field) {
                // multi_select stores an array, so containment is the question; select and
                // boolean store the bare JSON scalar, where whereJsonContains misses on
                // SQLite (json_each yields the unquoted text) — exact-match the raw
                // document instead, which every driver compares as plain text.
                $match = fn (Builder $query, mixed $value): Builder => $query->whereHas(
                    'customFieldValues',
                    fn (Builder $q) => $q->where('custom_field_id', $field->getKey())->when(
                        $field->type === 'multi_select',
                        fn (Builder $q) => $q->whereJsonContains('value', $value),
                        fn (Builder $q) => $q->where('value', json_encode($value)),
                    ),
                );

                if ($field->type === 'boolean') {
                    return TernaryFilter::make('cf_'.$field->code)
                        ->label($field->name)
                        ->queries(
                            true: fn (Builder $q) => $match($q, true),
                            // "No" means an explicit no — a record never saved with the field stays out.
                            false: fn (Builder $q) => $match($q, false),
                            blank: fn (Builder $q) => $q,
                        );
                }

                return SelectFilter::make('cf_'.$field->code)
                    ->label($field->name)
                    ->options(collect($field->options ?? [])->mapWithKeys(fn ($o) => [$o => $o])->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $match($query, $data['value'])
                        : $query);
            })
            ->values()
            ->all();
    }
}
