<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing to reverse — `docs/construction-management-plan.md` §3.2.
 *
 * A month's overhead allocation across two hundred codes, a labour run, an accrual and its reversal. The reason
 * it is a row rather than a predicate is stated plainly in the plan: **"a reversal that has to re-find its two
 * hundred rows by predicate is a reversal that will one day find a hundred and ninety-nine, and nothing will
 * say which one it missed."**
 */
class CostBatch extends Model
{
    use Auditable;

    public const KIND_MANUAL = 'manual';

    public const KIND_LABOUR = 'labour';

    public const KIND_ALLOCATION = 'allocation';

    public const KIND_ACCRUAL = 'accrual';

    public const KIND_REVERSAL = 'reversal';

    protected $table = 'construction_cost_batches';

    protected $fillable = [
        'kind', 'period_start', 'description', 'journal_entry_id', 'posted_at',
        'reversed_batch_id', 'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'posted_at' => 'datetime',
    ];

    protected $attributes = ['kind' => self::KIND_MANUAL];

    public function entries(): HasMany
    {
        return $this->hasMany(CostEntry::class, 'batch_id');
    }

    /** The batch this one backs out, if it is a reversal. */
    public function reversedBatch()
    {
        return $this->belongsTo(self::class, 'reversed_batch_id');
    }

    /** Whether something has already backed this batch out — one column rather than a scan of its rows. */
    public function isReversed(): bool
    {
        return static::query()->where('reversed_batch_id', $this->getKey())->exists();
    }

    /**
     * What the batch came to.
     *
     * Signed, so a batch and its reversal sum to zero — which is the cheapest possible check that a reversal
     * was complete, and the one a predicate-based reversal could not offer.
     */
    public function total(): float
    {
        return (float) $this->entries()->sum('amount');
    }
}
