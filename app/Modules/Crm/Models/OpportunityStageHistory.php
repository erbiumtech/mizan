<?php

namespace App\Modules\Crm\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every stage move, including moves backwards.
 *
 * **A move back is recorded, never overwritten.** That is what makes the sum of
 * `days_in_stage` meaningful — a deal that went to Proposal, back to Qualification, and
 * forward again spent time in each, and a table that kept only the latest position would
 * report the round trip as a single fast passage.
 *
 * `days_in_stage` is computed on the move and stored rather than derived later: deriving it
 * means reading the whole history on every read, and the figure never changes once the move
 * has happened.
 */
class OpportunityStageHistory extends Model
{
    protected $table = 'opportunity_stage_history';

    protected $fillable = [
        'opportunity_id', 'from_stage_id', 'to_stage_id',
        'moved_by', 'moved_at', 'days_in_stage',
    ];

    protected $casts = ['moved_at' => 'datetime', 'days_in_stage' => 'integer'];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'to_stage_id');
    }

    public function mover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }

    /** Whether this move went backwards down the pipeline. */
    public function isBackwards(): bool
    {
        // Both stages loaded explicitly, because this is asked of rows from anywhere — a report that
        // eager-loaded the history but not the stages behind it, a freshly created row, a test — and a
        // bare `->fromStage` there is a query per move, or a violation once lazy loading is off.
        $this->loadMissing(['fromStage', 'toStage']);

        return $this->fromStage && $this->toStage
            && $this->toStage->sort < $this->fromStage->sort;
    }
}
