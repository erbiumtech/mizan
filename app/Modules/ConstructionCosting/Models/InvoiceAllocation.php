<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceLine;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of a supplier invoice, attributed to a job and a cost code — `docs/construction-management-plan.md` §5.
 *
 * **Its own table rather than columns on `invoice_lines`**, because one line — "rebar, 12 t" — is routinely split
 * across two jobs and three cost codes. Columns would force a 1:1, and the workaround is splitting the invoice line,
 * "which makes the document this application prints disagree with the one the supplier sent, discovered months later
 * during a dispute".
 *
 * It also keeps construction's schema on construction's side of the boundary: three job-shaped columns on
 * `invoice_lines` would make Invoicing carry this module's schema.
 *
 * **The unallocated balance is the figure that matters**, and it is computed — invoice total less the sum of these
 * rows. A purchase invoice with no allocation leaves the general ledger perfectly correct and the job under-costed,
 * which §5 calls the single most likely silent failure in the module; a stored "allocated" flag would be one more
 * thing to forget.
 */
class InvoiceAllocation extends Model
{
    use Auditable;

    protected $table = 'construction_invoice_allocations';

    protected $fillable = [
        'invoice_id', 'invoice_line_id', 'job_id', 'wbs_node_id', 'cost_code_id',
        'commitment_line_id', 'amount', 'quantity', 'description', 'cost_entry_id', 'allocated_by',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class, 'invoice_line_id');
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

    /** The order line this invoice is against, which is what gives the three-way match its third leg. */
    public function commitmentLine(): BelongsTo
    {
        return $this->belongsTo(CommitmentLine::class, 'commitment_line_id');
    }

    /** The actual cost entry this allocation raised, which is what makes allocating idempotent. */
    public function costEntry(): BelongsTo
    {
        return $this->belongsTo(CostEntry::class, 'cost_entry_id');
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    public function isPosted(): bool
    {
        return $this->cost_entry_id !== null;
    }

    /** A credit note's allocation, which reduces job cost rather than adding to it. */
    public function isCredit(): bool
    {
        return (float) $this->amount < 0;
    }
}
