<?php

namespace App\Modules\Payroll\Listeners;

use App\Modules\Accounting\Models\Payment;
use App\Modules\Payroll\Events\PayslipReviewed;

/**
 * Copy a payslip's review decision onto the salary payment that waits on it.
 *
 * This is the write half of the denormalisation §8 Group C asks for: `Payment::isReleasable()` reads
 * `payments.subject_review` so that Accounting need not know what a Payslip is, and this keeps that copy
 * true. The reverse direction — Payroll knowing about Payment — is fine: Payroll already depends on
 * Accounting to post its journal entries.
 *
 * **The staleness this can produce, and why it is bounded.** A copy is only as good as its writer, so the
 * failure mode is a payslip reviewed without this running, leaving a payment blocked (or releasable) on
 * yesterday's answer. Three things bound it: `Payslip::recordReview()` is the only place a review is set
 * and it fires the event; the listener is synchronous, so there is no queue to be behind; and the
 * migration backfilled every existing row, so there is no pre-event history to be wrong. If a fourth way
 * to set a review ever appears, this is what it must also do — which is why PaymentReleaseGateTest
 * asserts the message a stale copy would produce.
 */
class CopyReviewOntoPayment
{
    public function handle(PayslipReviewed $event): void
    {
        $payslip = $event->payslip;

        // Queried here rather than through a `Payslip::payments()` relation, deliberately. This listener
        // is the one place Payroll reaches for a Payment on account of a review, and naming it here keeps
        // that integration visible instead of adding a relation to the model that every reader then has
        // to wonder about.
        //
        // Updated through the query builder rather than by loading the model: this runs inside the review
        // being recorded, and touching the payment's own timestamps or firing its observers would make a
        // payroll action look like an accounting one in the audit trail.
        Payment::query()->where('payslip_id', $payslip->getKey())->update([
            'subject_review' => $payslip->employee_review,
            'subject_review_reason' => $payslip->employee_rejection_reason,
            'subject_reviewed_at' => $payslip->employee_reviewed_at,
        ]);
    }
}
