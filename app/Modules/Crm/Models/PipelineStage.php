<?php

namespace App\Modules\Crm\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One column of the board.
 *
 * A row rather than an enum value because every company renames them, and a config array
 * would make a rename a deploy. `is_won` / `is_lost` exist so that reports never
 * pattern-match on a name somebody has since changed.
 */
class PipelineStage extends Model
{
    use Auditable;

    protected $fillable = [
        'pipeline_id', 'name', 'sort', 'probability_pct',
        'is_won', 'is_lost', 'rot_after_days',
    ];

    protected $casts = [
        'sort' => 'integer',
        'probability_pct' => 'integer',
        'is_won' => 'boolean',
        'is_lost' => 'boolean',
        'rot_after_days' => 'integer',
    ];

    protected $attributes = [
        'sort' => 0,
        'probability_pct' => 0,
        'is_won' => false,
        'is_lost' => false,
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'pipeline_stage_id');
    }

    public function isTerminal(): bool
    {
        return $this->is_won || $this->is_lost;
    }

    /**
     * Whether a deal sitting here this long counts as rotting.
     *
     * Terminal stages never rot: a won deal that has not moved in ninety days is a won
     * deal, not a problem.
     */
    public function rotsAfter(): ?int
    {
        return $this->isTerminal() ? null : $this->rot_after_days;
    }
}
