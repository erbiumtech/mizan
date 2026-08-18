<?php

namespace App\Modules\Payroll\Events;

use App\Modules\Payroll\Models\Payslip;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An employee has accepted or rejected their payslip.
 *
 * Fired so that anything holding a copy of that decision can update it. Today there is one such thing:
 * a salary payment refuses to be released until the payslip is accepted, and it reads its own
 * `subject_review` column rather than the payslip — which is what lets Accounting be packaged without
 * Payroll. See docs/module-packaging-plan.md §8 Group C.
 *
 * Synchronous, not queued, and that is the point: the bank-file screen is often the *next* thing somebody
 * opens after recording a review, and a payment that is still blocked because a queue worker has not run
 * yet would read as a bug in payroll. The listener is a single-row update.
 */
class PayslipReviewed
{
    use Dispatchable;

    public function __construct(public readonly Payslip $payslip) {}
}
