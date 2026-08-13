<?php

namespace App\Support;

/**
 * Whether a thing must be approved by somebody other than the person who raised it.
 *
 * Extracted from Accounting's SecondApproverRule when `leave` became its second
 * caller, which is exactly when docs/hrms-plan.md §11 said to generalise it and not
 * before. The reasoning it carried is unchanged and worth restating here, because it
 * is the reason this is a setting rather than a law:
 *
 * Segregation of duties is the right default. It also assumes a second person
 * exists — a company run by one operator has nobody to route a request to, so the
 * rule stops being a control and becomes a dead end. In the ledger that is not
 * hypothetical: it is how a month's payroll came to be paid while its accrual was
 * never posted. Turned off, the author may approve their own, and the audit trail
 * records that this is what happened, because a waived control that leaves no trace
 * is worse than no control at all.
 *
 * Two places answer, in this order: the company's own saved choice, then the
 * installation default in .env for every company that has never chosen. A company
 * that opts out stays opted out whatever the env later says — it has answered the
 * question for itself and a deploy should not answer it again.
 *
 * Deliberately NOT a generic approval engine. docs/hrms-plan.md §11 is explicit
 * about that: EmployeeChangeRequest, expenses, advances and journal entries each
 * carry their own approver logic and they *disagree* rather than merely duplicate
 * (`rejected` vs `refused`, `reviewed_by` vs `decided_by` vs `approved_by`, and
 * advances has no approval at all). Reconciling four vocabularies across four
 * working modules would lengthen ModuleBoundaryTest::KNOWN_COUPLINGS, not shorten
 * it. This class is the one piece that genuinely is the same question twice.
 */
abstract class SelfApprovalPolicy
{
    /** The `setting()` key this policy reads, e.g. 'leave.require_second_approver'. */
    abstract public function settingKey(): string;

    /** True when the request must be decided by somebody other than its author. */
    public function isRequired(): bool
    {
        return (bool) setting($this->settingKey());
    }

    /** The mirror image, for call sites that read better in the positive. */
    public function allowsSelfApproval(): bool
    {
        return ! $this->isRequired();
    }

    public function set(bool $required): void
    {
        app(TenantSettings::class)->set($this->settingKey(), $required);
    }

    /** What a company falls back to when it has never chosen — the .env default. */
    public function default(): bool
    {
        return (bool) config($this->settingKey());
    }
}
