<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Support\ModuleMap;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One thing that reduced a commitment — `docs/construction-management-plan.md` §5.
 *
 * **Relief is an explicit row, not a subtraction**, and the alternative is worth naming because it is what most
 * systems do: *committed = ordered − invoiced*, matched by job, code and supplier. That heuristic fails the first
 * time one invoice covers two orders or one line is part-delivered, and it fails by leaving an over-commitment
 * nobody can point at and nobody can clear. With rows, open commitment is **provable** as
 * `line.amount − Σ reliefs`.
 *
 * **Signed, one convention:** positive relieves, negative gives commitment back. A cancelled receipt is a negative
 * relief rather than a deleted row, so "what did we think was committed in March" stays answerable — the discipline
 * the cost ledger keeps with reversals, for the same reason.
 */
class CommitmentRelief extends Model
{
    use Auditable;

    public const KIND_RECEIPT = 'receipt';

    public const KIND_CERTIFICATE = 'certificate';

    public const KIND_INVOICE = 'invoice';

    public const KIND_CANCELLATION = 'cancellation';

    public const KIND_CLOSE_OUT = 'close_out';

    /**
     * The kinds that need a stated reason.
     *
     * A cancellation and a close-out both leave money uncommitted that somebody ordered, and "the supplier
     * delivered short and we agreed to leave it" is a different fact from "somebody forgot".
     *
     * @var array<int, string>
     */
    public const REASON_REQUIRED = [self::KIND_CANCELLATION, self::KIND_CLOSE_OUT];

    protected $table = 'construction_commitment_reliefs';

    protected $fillable = [
        'commitment_line_id', 'kind', 'source_type', 'source_id', 'amount', 'quantity',
        'relieved_on', 'reference', 'reason', 'created_by',
    ];

    protected $casts = [
        'relieved_on' => 'date',
    ];

    /**
     * The morph alias, written through `ModuleMap::alias()`.
     *
     * `source_type` is a **plain column** and `enforceMorphMap()` does not cover those — §18.2 names this family as
     * the five it misses. Without the mutator the fully-qualified class name goes into the column, and the day that
     * class moves, the query that traces a relief back to its goods receipt stops matching with no error at all.
     */
    public function setSourceTypeAttribute(?string $value): void
    {
        $this->attributes['source_type'] = $value ? ModuleMap::alias($value) : null;
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(CommitmentLine::class, 'commitment_line_id');
    }

    /** The goods receipt, certificate or invoice allocation this came from. */
    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    /** Whether this relief gave commitment back rather than taking it. */
    public function isReversal(): bool
    {
        return (float) $this->amount < 0;
    }
}
