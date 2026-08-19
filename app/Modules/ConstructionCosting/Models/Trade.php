<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\CostCode;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A trade — carpenter, steel fixer, mason, plant operator. `docs/construction-management-plan.md` §7.1.
 *
 * **There is no rate column here, and its absence is the point of §7.2.** The phrase in §7.1 is
 * "`construction_trades` with default codes and rates": the default *code* is a column, and the default *rate* is a
 * `construction_labour_rates` row with `trade_id` set and everything else null — the "company default" tier of the
 * ladder. A `cost_rate_per_hour` column here would restate every month's labour cost the moment somebody edited it,
 * with no journal, no audit and no report of what moved, which is the failure the dated table exists to prevent.
 *
 * Per tenant and shared across every job, like the cost-code library and for the same reason (§2.2): "what does an
 * hour of steel fixing cost us" is a question asked across jobs, and it is unanswerable the moment each job invents
 * its own trades.
 */
class Trade extends Model
{
    use Auditable;

    protected $table = 'construction_trades';

    protected $fillable = [
        'code', 'name', 'description', 'default_cost_code_id', 'is_active', 'sort',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
        'sort' => 0,
    ];

    /** A default, never a rule: the labour record's own `cost_code_id` is the authority (§7.1). */
    public function defaultCostCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class, 'default_cost_code_id');
    }

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class, 'trade_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(LabourRate::class, 'trade_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function displayName(): string
    {
        return "{$this->code} — {$this->name}";
    }
}
