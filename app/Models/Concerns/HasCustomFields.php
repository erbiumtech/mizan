<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * Opt a tenant domain model into per-company custom fields. Values are stored in
 * `custom_field_values` (tenant DB) keyed to this record.
 */
trait HasCustomFields
{
    /** Per-(model, company) definition cache — avoids re-querying per row. */
    protected static array $customFieldDefsCache = [];

    /** Per-instance value cache — avoids N×columns queries in tables. */
    protected ?array $customFieldsDataCache = null;

    public function customFieldValues(): MorphMany
    {
        return $this->morphMany(CustomFieldValue::class, 'entity');
    }

    /** Active field definitions for this model type (cached per company). */
    public static function customFieldDefinitions(): Collection
    {
        // Only cache within a real tenant context (production requests). Without
        // a current company (e.g. tests) always query fresh, so a stale cache
        // never leaks across tenants/tests.
        $companyId = Company::current()?->getKey();

        if ($companyId === null) {
            return CustomField::query()->forModel(static::class)->get();
        }

        return static::$customFieldDefsCache[static::class.'@'.$companyId]
            ??= CustomField::query()->forModel(static::class)->get();
    }

    /** Current custom field values keyed by field code (for form hydration). */
    public function customFieldsData(): array
    {
        if ($this->customFieldsDataCache !== null) {
            return $this->customFieldsDataCache;
        }

        // Reuse eager-loaded values (->with('customFieldValues.customField')) when present.
        $values = ($this->relationLoaded('customFieldValues')
            ? $this->customFieldValues
            : $this->customFieldValues()->with('customField')->get())
            ->keyBy(fn (CustomFieldValue $v) => $v->customField?->code);

        return $this->customFieldsDataCache = static::customFieldDefinitions()
            ->mapWithKeys(fn (CustomField $f) => [$f->code => $values->get($f->code)?->value])
            ->all();
    }

    /**
     * Persist a map of [code => value] to this record's custom field values.
     */
    public function saveCustomFields(array $data): void
    {
        $fields = static::customFieldDefinitions()->keyBy('code');

        foreach ($data as $code => $value) {
            $field = $fields->get($code);

            if (! $field) {
                continue;
            }

            $this->customFieldValues()->updateOrCreate(
                ['custom_field_id' => $field->getKey()],
                ['value' => $value],
            );
        }
    }
}
