<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Location;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery — `docs/construction-management-plan.md` §5, "where cost first touches the job".
 *
 * Posting one does two things and deliberately not a third: it **relieves the order** and **raises an accrual at
 * order rate**. The stock movement §5 also describes needs `stock_locations`, which Phase 8 delivers; until then a
 * line destined for a store is refused with a message naming what is missing, rather than costed as though it had
 * been stocked.
 *
 * **The accrual is the point of posting on the day.** Between delivery and invoice the job has incurred cost that no
 * supplier document yet proves, and a cost report that waited for the invoice would understate every month end. Order
 * rate is the honest estimate available; where the invoice disagrees, §5's three-way match makes that somebody's
 * decision rather than a silent adjustment.
 */
class GoodsReceipt extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    protected $table = 'construction_goods_receipts';

    protected $fillable = [
        'number', 'commitment_id', 'contact_id', 'received_on', 'delivery_note_reference',
        'received_by', 'location_id', 'status', 'posted_at', 'reversal_reason', 'notes',
    ];

    protected $casts = [
        'received_on' => 'date',
        'posted_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    public function commitment(): BelongsTo
    {
        return $this->belongsTo(Commitment::class, 'commitment_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'goods_receipt_id');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function total(): float
    {
        return round((float) $this->lines()->sum('amount'), 2);
    }

    public function displayName(): string
    {
        return $this->number.($this->delivery_note_reference ? " (DN {$this->delivery_note_reference})" : '');
    }
}
