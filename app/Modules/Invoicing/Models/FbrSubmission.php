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
 * Written by SubmitInvoiceToFbr (phase 5), one row per logical submission: the
 * unique `idempotency_key` is fixed at dispatch, so a retry updates its row and
 * bumps `attempt` rather than handing the integrator a key it has already seen.
 * The only driver is still the null one — nothing transmits anything until
 * phase 4 (docs/fbr-digital-invoicing-plan.md §3, §9.4).
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
