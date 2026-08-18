<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a commitment — `docs/construction-management-plan.md` §5.
 *
 * **The job is on the line, not the header**, and §5 spends a paragraph on why: one order of rebar split across
 * three sites is completely normal. Forcing one order per job means either the supplier gets three orders for one
 * delivery — which he will not honour, and the delivery note then matches nothing — or somebody codes the whole
 * load to one job. "Both are silent cost misallocation and the second is invisible."
 *
 * The open balance is **per line**, because relief is per line: a part delivery against line two says nothing about
 * line five, and a commitment whose openness could only be read in total would report the whole order as open until
 * the last item arrived.
 */
class CommitmentLine extends Model
{
    use Auditable;

    protected $table = 'construction_commitment_lines';

    protected $fillable = [
        'commitment_id', 'requisition_line_id', 'job_id', 'wbs_node_id', 'cost_code_id', 'product_id',
        'description', 'quantity', 'unit_of_measure', 'rate', 'amount', 'cost_type',
    ];

    protected $attributes = [
        'amount' => 0,
        'cost_type' => 'material',
    ];

    /**
     * The amount follows quantity × rate where both are given, and the cost type is snapshotted off the code.
     *
     * Both here rather than in a form: an order will also arrive from a requisition and from an import, and §3.2's
     * reason for snapshotting holds identically — re-typing a cost code in June must not restate what March
     * committed.
     */
    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            if ($line->quantity !== null && $line->rate !== null) {
                $line->amount = round((float) $line->quantity * (float) $line->rate, 2);
            }

            if ($line->isDirty('cost_code_id') && $line->cost_code_id) {
                $line->cost_type = CostCode::query()->find($line->cost_code_id)?->cost_type ?? $line->cost_type;
            }
        });
    }

    public function commitment(): BelongsTo
    {
        return $this->belongsTo(Commitment::class, 'commitment_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function wbsNode(): BelongsTo
    {
        return $this->belongsTo(WbsNode::class, 'wbs_node_id');
    }

    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class, 'cost_code_id');
    }

    public function reliefs(): HasMany
    {
        return $this->hasMany(CommitmentRelief::class, 'commitment_line_id');
    }

    /**
     * The request this line satisfies, where it came from one.
     *
     * Null on most order lines in most companies, which raise orders without a requisition at all — the link exists
     * so that "asked for and not yet ordered" is answerable, not because every order must have a parent.
     */
    public function requisitionLine(): BelongsTo
    {
        return $this->belongsTo(RequisitionLine::class, 'requisition_line_id');
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    /** Lines whose commitment actually commits money — issued or partially relieved. */
    public function scopeCommitting(Builder $query): Builder
    {
        return $query->whereHas('commitment', fn (Builder $commitment) => $commitment->committing());
    }

    public function relievedTotal(): float
    {
        return round((float) $this->reliefs()->sum('amount'), 2);
    }

    /** Relieved by receipt or certificate — what the invoice may **not** relieve again (§5's double-relief rule). */
    public function receivedTotal(): float
    {
        return round((float) $this->reliefs()
            ->whereIn('kind', [CommitmentRelief::KIND_RECEIPT, CommitmentRelief::KIND_CERTIFICATE])
            ->sum('amount'), 2);
    }

    public function invoicedTotal(): float
    {
        return round((float) $this->reliefs()->where('kind', CommitmentRelief::KIND_INVOICE)->sum('amount'), 2);
    }

    /** What is still promised on this line. */
    public function openAmount(): float
    {
        return round(max(0.0, (float) $this->amount - $this->relievedTotal()), 2);
    }

    public function isFullyRelieved(): bool
    {
        return $this->relievedTotal() >= (float) $this->amount;
    }

    public function displayName(): string
    {
        return str($this->description)->limit(60)->toString();
    }
}
