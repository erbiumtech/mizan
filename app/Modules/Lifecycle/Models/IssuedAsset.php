<?php

namespace App\Modules\Lifecycle\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kit somebody was given, and whether it came back.
 *
 * `fixed_asset_id` links to Accounting when the laptop is on the books; a phone that
 * was never capitalised has a description and no link. Guarded on `accounting`, so the
 * register works for a company that keeps its books elsewhere.
 */
class IssuedAsset extends Model
{
    use Auditable;
    use StoresPlainDates;

    protected array $plainDates = ['issued_on', 'returned_on'];

    /**
     * The kit a company issues. A plain string column, not an enum, so this list
     * is the only place kinds live — add to it and the form offers the new value
     * (labelled by ucfirst(str_replace('_', ' '))), existing rows untouched.
     * `other` stays last, the catch-all for anything not worth its own entry.
     *
     * @var array<int, string>
     */
    public const KINDS = [
        'laptop', 'phone', 'sim', 'vehicle', 'access_card',
        'monitor', 'keyboard', 'mouse', 'headphone', 'webcam',
        'docking_station', 'ups', 'charger', 'computer_table',
        'other',
    ];

    /**
     * Labels the default slug title-casing gets wrong — acronyms that should stay
     * upper-case rather than become "Sim" / "Ups". Everything else reads fine from
     * the slug, so only the exceptions are listed.
     *
     * @var array<string, string>
     */
    public const KIND_LABELS = ['sim' => 'SIM', 'ups' => 'UPS'];

    /** How a kind reads on screen: the acronym override, else the slug title-cased. */
    public static function kindLabel(string $kind): string
    {
        return self::KIND_LABELS[$kind] ?? ucfirst(str_replace('_', ' ', $kind));
    }

    /** @return array<string, string> value => label, for a select */
    public static function kindOptions(): array
    {
        return array_combine(self::KINDS, array_map(self::kindLabel(...), self::KINDS));
    }

    protected $fillable = [
        'employee_id', 'asset_kind', 'description', 'serial_no', 'fixed_asset_id',
        'issued_on', 'returned_on', 'condition_note', 'value',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'returned_on' => 'date',
        'value' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    /** Still out. What a final settlement charges for if it stays that way. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('returned_on');
    }

    public function isReturned(): bool
    {
        return $this->returned_on !== null;
    }
}
