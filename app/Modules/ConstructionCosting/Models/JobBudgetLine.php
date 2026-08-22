<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One budgeted control account — §3.5.
 *
 * `period_start` is nullable and **the null carries meaning**: a phased line says which month its budget belongs
 * to, which is what makes planned value and therefore schedule variance computable. A null means nobody phased
 * it, and §14 requires the report to say "schedule performance unavailable" rather than showing zero.
 */
class JobBudgetLine extends Model
{
    use Auditable;

    protected $table = 'construction_budget_lines';

    protected $fillable = [
        'budget_version_id', 'job_id', 'wbs_node_id', 'cost_code_id', 'cost_type',
        'quantity', 'unit_of_measure', 'unit_rate', 'amount', 'period_start', 'description',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_rate' => 'decimal:4',
        'amount' => 'decimal:2',
        'period_start' => 'date',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(JobBudget::class, 'budget_version_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class, 'cost_code_id');
    }

    public function wbsNode(): BelongsTo
    {
        return $this->belongsTo(WbsNode::class, 'wbs_node_id');
    }

    /** Budget planned to be earned on or before a date — planned value, which SV and SPI need. */
    public function scopePhasedUpTo(Builder $query, string $date): Builder
    {
        return $query->whereNotNull('period_start')->whereDate('period_start', '<=', $date);
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }
}
