<?php

namespace App\Modules\Invoicing\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to report one invoice to FBR.
 *
 * A log, not a state: `Invoice::$fbr_status` is where the answer lives, and this
 * records how it was arrived at. That separation is what makes a rejection
 * diagnosable — the invoice says "rejected", and the row here says which field
 * FBR objected to, on which attempt, through which integrator.
 *
 * Nothing writes to this yet. There is no integrator driver (see
 * docs/fbr-digital-invoicing-plan.md §3 for why one is not designed against an
 * API nobody here has seen), so the table exists ahead of its writer —
 * deliberately, because the reconciliation report and the void rules are built
 * against this shape and are worth having before transmission starts.
 */
class FbrSubmission extends Model
{
    /** FBR accepted the invoice and returned an IRN. */
    public const OUTCOME_ACCEPTED = 'accepted';

    /** FBR answered, and said no. A data problem to fix and resubmit. */
    public const OUTCOME_REJECTED = 'rejected';

    /**
     * We never heard back — a timeout, a network failure, a 5xx.
     *
     * Deliberately distinct from `rejected`, and the distinction matters more
     * than it looks: a rejection is known not to have landed, while an error may
     * have landed anyway. Only one of the two is safe to retry blindly, which is
     * what `idempotency_key` exists for.
     */
    public const OUTCOME_ERROR = 'error';

    protected $fillable = [
        'invoice_id', 'attempt', 'driver', 'idempotency_key',
        'request_payload', 'response_payload', 'http_status',
        'outcome', 'error_code', 'error_message', 'submitted_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'attempt' => 'integer',
        'http_status' => 'integer',
        'submitted_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
