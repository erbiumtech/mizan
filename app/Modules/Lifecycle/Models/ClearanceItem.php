<?php

namespace App\Modules\Lifecycle\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing to settle before somebody leaves — docs/saas-launch-plan.md §14.5. Auto from the records, or by hand; cleared or waived. */
class ClearanceItem extends Model
{
    public const KIND_AUTO = 'auto';

    public const KIND_MANUAL = 'manual';

    protected $fillable = ['separation_id', 'title', 'kind', 'source', 'amount', 'cleared_at', 'cleared_by', 'waived_at', 'waived_by', 'waive_reason', 'note', 'sort'];

    protected $casts = ['amount' => 'decimal:2', 'cleared_at' => 'datetime', 'waived_at' => 'datetime', 'sort' => 'integer'];

    public function separation(): BelongsTo
    {
        return $this->belongsTo(Separation::class);
    }

    public function isClear(): bool
    {
        return $this->cleared_at !== null || $this->waived_at !== null;
    }
}
