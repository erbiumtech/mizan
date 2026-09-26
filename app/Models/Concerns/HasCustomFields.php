<?php

namespace App\Models\Concerns;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CustomField;
use App\Modules\Core\Models\CustomFieldValue;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

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
            ->mapWithKeys(fn (CustomField $f) => [$f->code => static::decodeCustomFieldValue($f, $values->get($f->code)?->value)])
            ->all();
    }

    /**
     * Decrypt an encrypted field's stored value. A plaintext value written
     * before the field was flagged encrypted passes through unchanged rather
     * than erroring the whole record.
     */
    protected static function decodeCustomFieldValue(CustomField $field, mixed $value): mixed
    {
        if (! $field->is_encrypted || ! is_string($value)) {
            return $value;
        }

        try {
            return json_decode(Crypt::decryptString($value), true);
        } catch (DecryptException) {
            return $value;
        }
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

            // ponytail: encrypted values are opaque ciphertext at rest, so they can't be
            // filtered or searched; a blind-index column is the upgrade path if ever needed.
            if ($field->is_encrypted && $value !== null && $value !== '') {
                $value = Crypt::encryptString(json_encode($value));
            }

            $this->customFieldValues()->updateOrCreate(
                ['custom_field_id' => $field->getKey()],
                ['value' => $value],
            );
        }
    }
}
