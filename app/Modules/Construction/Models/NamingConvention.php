<?php

namespace App\Modules\Construction\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One job's ISO 19650 naming convention: the field order, and the codes allowed in each field.
 *
 * **Per job, not hardcoded** — §15 is explicit that the standard mandates the *fields* while every project
 * issues its own code lists for what goes in them, and that hardcoding the codes makes the module unusable on
 * job number two. The same applies to suitability: S0–S7 with A1–A5 and B1–B5 is the UK ISO 19650-2 set, and
 * AIA-land issues "for construction", "for approval" and "as-built".
 *
 * An absent or empty code list means **any value is allowed**, which is what a project that has not issued its
 * lists yet actually needs. A convention that blocked every upload until somebody typed out seven code lists
 * would be worked around on the first day.
 */
class NamingConvention extends Model
{
    use Auditable;

    /** The fields ISO 19650 names, in the order most projects assemble them. */
    public const FIELDS = [
        'project_code',
        'originator_code',
        'functional_code',
        'spatial_code',
        'form_code',
        'discipline_code',
        'container_number',
    ];

    protected $table = 'construction_naming_conventions';

    protected $fillable = [
        'job_id', 'name', 'separator', 'field_order', 'code_lists', 'suitability_codes', 'is_default',
    ];

    protected $casts = [
        'field_order' => 'array',
        'code_lists' => 'array',
        'suitability_codes' => 'array',
        'is_default' => 'boolean',
    ];

    protected $attributes = [
        'separator' => '-',
        'is_default' => false,
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /** @return array<int, string> */
    public function fields(): array
    {
        $order = $this->field_order ?: self::FIELDS;

        // Only fields this application actually has columns for: a convention naming something else would
        // otherwise assemble an identifier out of a value nowhere stores.
        return array_values(array_intersect($order, self::FIELDS));
    }

    /**
     * The allowed codes for a field, or an empty array meaning "no list issued, any value".
     *
     * @return array<int, string>
     */
    public function codesFor(string $field): array
    {
        return array_values((array) ($this->code_lists[$field] ?? []));
    }

    /** Whether a value is allowed in a field. True when no list has been issued for it. */
    public function allows(string $field, ?string $value): bool
    {
        $codes = $this->codesFor($field);

        if ($codes === []) {
            return true;
        }

        return $value !== null && in_array($value, $codes, true);
    }

    /** Whether a suitability code is one this project issues. True when the project has issued no list. */
    public function allowsSuitability(?string $code): bool
    {
        $codes = array_values((array) ($this->suitability_codes ?? []));

        if ($codes === []) {
            return true;
        }

        return $code !== null && in_array($code, $codes, true);
    }
}
