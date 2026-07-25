<?php

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\TenantModel as Model;
use App\Traits\Auditable;

class FixedAsset extends Model
{
    use Auditable, HasCustomFields;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FULLY_DEPRECIATED = 'fully_depreciated';

    public const STATUS_DISPOSED = 'disposed';

    protected $fillable = [
        'asset_code', 'name', 'account_id', 'purchase_date', 'purchase_cost',
        'depreciation_method', 'useful_life_months', 'salvage_value',
        'accumulated_depreciation', 'status', 'disposed_at', 'journal_entry_id',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'purchase_cost' => 'decimal:2',
        'salvage_value' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'disposed_at' => 'datetime',
    ];

    protected $attributes = [
        'depreciation_method' => 'straight_line',
        'salvage_value' => 0,
        'accumulated_depreciation' => 0,
        'status' => self::STATUS_ACTIVE,
    ];

    protected static function booted()
    {
        static::creating(function (FixedAsset $asset) {
            if (empty($asset->asset_code)) {
                $last = static::orderByDesc('id')->first();
                $asset->asset_code = sprintf('FA-%04d', ($last?->id ?? 0) + 1);
            }
        });
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function purchaseEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * Depreciation/disposal entries booked against this asset.
     */
    public function journalEntries()
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    public function getBookValueAttribute(): float
    {
        return round((float) $this->purchase_cost - (float) $this->accumulated_depreciation, 2);
    }

    public function getDepreciableBaseAttribute(): float
    {
        return round((float) $this->purchase_cost - (float) $this->salvage_value, 2);
    }

    public function remainingDepreciable(): float
    {
        return round($this->depreciable_base - (float) $this->accumulated_depreciation, 2);
    }

    /**
     * The depreciation amount for one month, before capping at the
     * remaining depreciable base.
     */
    public function monthlyDepreciation(): float
    {
        if ($this->useful_life_months < 1) {
            return 0.0;
        }

        if ($this->depreciation_method === 'declining_balance') {
            // Double-declining: monthly rate = 2 / life.
            $rate = 2 / $this->useful_life_months;

            return round($this->book_value * $rate, 2);
        }

        return round($this->depreciable_base / $this->useful_life_months, 2);
    }

    public function isDepreciable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->remainingDepreciable() > 0;
    }
}
