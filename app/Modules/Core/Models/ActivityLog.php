<?php

namespace App\Modules\Core\Models;

use App\Support\Impersonation;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity as SpatieActivity;
use Throwable;

/**
 * Tenant-aware activity log. The `activity_log` table lives in the landlord
 * database (shared across companies), so each entry is tagged with the current
 * company id on write, and reads are scoped to the current company when one is
 * active. When no tenant is current (landlord/CLI context) nothing is scoped.
 */
class ActivityLog extends SpatieActivity
{
    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            if ($activity->company_id === null) {
                $activity->company_id = Company::current()?->getKey();
            }

            $activity->stampImpersonator();
        });

        static::addGlobalScope('tenant', function (Builder $query): void {
            if ($companyId = Company::current()?->getKey()) {
                $query->where($query->getModel()->getTable().'.company_id', $companyId);
            }
        });
    }

    /**
     * Record who was really at the keyboard when an administrator is signed in as
     * somebody else.
     *
     * The causer stays the impersonated user, because that is whose data changed
     * and whose record it is. But without this, an administrator accepting a
     * salary change on an employee's behalf would be indistinguishable from the
     * employee accepting it — and that acknowledgement is a statement of consent.
     * Stamped here rather than at each call site so it covers every audited
     * change, including the ones written automatically by the Auditable trait.
     */
    protected function stampImpersonator(): void
    {
        // Resolved lazily and defensively: activity is logged from console
        // commands and queued jobs too, where there is no session at all.
        try {
            $impersonator = app(Impersonation::class)->impersonator();
        } catch (Throwable) {
            return;
        }

        if (! $impersonator) {
            return;
        }

        $properties = collect($this->properties ?? []);

        $this->properties = $properties->put('impersonated_by', [
            'id' => $impersonator->getKey(),
            'email' => $impersonator->email,
            'name' => $impersonator->name,
        ])->all();
    }

    /**
     * Repair invalid UTF-8 before the `collection` cast tries to JSON-encode it.
     *
     * `json_encode()` refuses a string containing malformed UTF-8, and the cast turns
     * that refusal into a `JsonEncodingException`. Because the properties are assigned
     * — not saved — by `ActivityLogger`, that throw happens inside the `created` event
     * of whatever model is being audited, which means **an unwritable audit line aborts
     * the business transaction that triggered it**: a single mis-encoded byte in an
     * employee's name was enough to fail a payroll posting outright.
     *
     * An audit record is a description of something that already happened, so the only
     * defensible failure mode is to record it imperfectly. A set mutator is the one
     * place that can intervene: `setAttribute()` consults `hasSetMutator()` before it
     * reaches the JSON cast, so this runs while the value is still a live PHP value.
     */
    public function setPropertiesAttribute(mixed $value): void
    {
        // Null is stored as SQL NULL, not as the string "null": `setAttribute()` skips the
        // JSON cast for null, and taking over the assignment must not change that.
        $this->attributes['properties'] = $value === null
            ? null
            : $this->castAttributeAsJson('properties', static::toValidUtf8($value));
    }

    /**
     * Coerce every string in a nested structure to valid UTF-8, keys included — a
     * malformed key fails the encode just as surely as a malformed value.
     *
     * `mb_convert_encoding()` from UTF-8 to UTF-8 is the sanitising idiom: byte
     * sequences that are already valid pass through untouched, so a legitimate em dash
     * or an accented name survives, and only the genuinely broken bytes are replaced.
     */
    protected static function toValidUtf8(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8')
                ? $value
                : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if (! is_array($value)) {
            return $value;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            $clean[is_string($key) ? static::toValidUtf8($key) : $key] = static::toValidUtf8($item);
        }

        return $clean;
    }
}
