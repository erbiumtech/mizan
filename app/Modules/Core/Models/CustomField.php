<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Support\ModuleMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A per-tenant custom field definition attached to a domain model type.
 */
class CustomField extends Model
{
    public const TYPES = ['text', 'textarea', 'number', 'date', 'boolean', 'select', 'multi_select', 'color', 'rich_text'];

    protected $fillable = [
        'model_type', 'code', 'name', 'type', 'options', 'is_required',
        'min', 'max', 'regex', 'help', 'placeholder', 'sort', 'is_active',
        'visible_when_field', 'visible_when_value', 'is_encrypted',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'is_encrypted' => 'boolean',
    ];

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /**
     * Normalise on write, so `model_type` holds the stable alias whatever the
     * caller passed. The Filament Select already offers aliases, but seeders,
     * imports and tests hand over `Contact::class` directly — and a raw class
     * name written here is a row that stops matching the day the model moves.
     */
    public function setModelTypeAttribute(?string $value): void
    {
        $this->attributes['model_type'] = $value === null ? null : ModuleMap::alias($value);
    }

    /**
     * `model_type` holds the model's stable alias, not its current class name, so
     * a definition survives the model moving into its module directory. Callers
     * keep passing `::class` — the translation happens here, in one place.
     */
    public function scopeForModel(Builder $query, string $modelClass): Builder
    {
        return $query->where('model_type', ModuleMap::alias($modelClass))
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('id');
    }

    /**
     * CSV header names for this model's custom fields (`cf_<code>`), for the
     * importers that carry custom-field columns. Prefixed so a code can never
     * shadow a native column of the import.
     *
     * @return array<int, string>
     */
    public static function csvColumns(string $modelClass): array
    {
        return self::query()->forModel($modelClass)
            ->pluck('code')
            ->map(fn (string $code): string => 'cf_'.$code)
            ->all();
    }

    /**
     * [code => value] from a CSV row's `cf_*` cells, ready for `saveCustomFields()`.
     * Blank cells are skipped, so a re-import never clears a value it didn't carry.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    public static function csvValues(string $modelClass, array $row): array
    {
        $values = [];

        foreach (self::query()->forModel($modelClass)->get() as $field) {
            $raw = $row['cf_'.$field->code] ?? '';

            if ($raw === '') {
                continue;
            }

            $values[$field->code] = match ($field->type) {
                'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                'multi_select' => array_values(array_filter(array_map('trim', explode('|', $raw)))),
                default => $raw,
            };
        }

        return $values;
    }
}
