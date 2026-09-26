<?php

namespace App\Modules\Invoicing\Fbr;

/**
 * One FBR integrator, as a driver — docs/fbr-digital-invoicing-plan.md §3.
 *
 * Integration is only through PRAL or an FBR-licensed integrator, multiple
 * integrators are expressly permitted, and a company may change theirs. So the
 * vendor is per-company configuration (`fbr.driver`, a TenantSettings override
 * like every other fbr.* key) and each integrator is one implementation of
 * this.
 *
 * Only `submit()`, deliberately. The plan's §3 sketch also names `cancel` and
 * `status`, but both describe calls against an API nobody here has seen
 * (§9.4), nothing in the application calls either yet — the within-window
 * cancellation flow tells the user to cancel in FBR's own system — and adding
 * a method to an interface with one in-repo implementation is free when phase
 * 4 defines them.
 */
interface FbrDriver
{
    /** What `fbr_submissions.driver` records: an attempt is only interpretable next to the driver that made it. */
    public function name(): string;

    /**
     * Report one invoice to FBR.
     *
     * `$payload` is the canonical shape SubmitInvoiceToFbr builds (the field
     * list is §9.5's to confirm); a driver maps it to its integrator's wire
     * format. `$idempotencyKey` is stable across retries of the same logical
     * submission and MUST be sent, so an integrator that recorded the invoice
     * before timing out can refuse the duplicate — a duplicate at FBR is a
     * correction that needs the Commissioner.
     *
     * Never throws for an outcome FBR can express: an acceptance, a rejection
     * and a never-heard-back are all FbrResponse outcomes, because the caller
     * has to log all three the same way before deciding what to do.
     */
    public function submit(array $payload, string $idempotencyKey): FbrResponse;
}
