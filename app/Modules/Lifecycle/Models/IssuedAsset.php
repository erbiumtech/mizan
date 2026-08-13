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

    /** @var array<int, string> */
    public const KINDS = ['laptop', 'phone', 'sim', 'vehicle', 'access_card', 'other'];

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
