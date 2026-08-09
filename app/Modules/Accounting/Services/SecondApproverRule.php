<?php

namespace App\Modules\Accounting\Services;

use App\Support\TenantSettings;

/**
 * Whether a journal entry needs a second person to approve it, per company.
 *
 * Segregation of duties is the right default and it is the default here: whoever
 * writes an entry must not be the one who waves it through. Every accounting
 * control in this application assumes it.
 *
 * It also assumes there IS a second person. A company run by one operator has
 * nobody to route an entry to, so the rule stops being a control and becomes a
 * dead end — the entry sits at pending_approval forever, and the ledger quietly
 * disagrees with the bank. That is not a hypothetical: it is how a month's
 * payroll came to be paid while its accrual was never posted, leaving Salaries
 * Payable at minus the whole month.
 *
 * So the control is a company setting rather than a law. Turned off, an entry
 * may be approved by its own author — and the audit trail says that is what
 * happened, because a waived control that leaves no trace is worse than no
 * control at all.
 *
 * Two places answer the question, in this order:
 *
 *   1. the company's own choice, saved from Company Settings → Approvals;
 *   2. ACCOUNTING_REQUIRE_SECOND_APPROVER in .env, the installation default,
 *      for every company that has never chosen.
 *
 * An installation that only ever serves the one-operator company sets the env
 * var to false and never touches the page; a company that opts out on the page
 * stays opted out whatever the env later says, because it has answered the
 * question for itself and a deploy should not answer it again.
 *
 * Deliberately separate from accounting.auto_post_payroll, which answers a
 * narrower question: whether PAYROLL entries skip the queue. This one decides
 * whether the queue can be cleared by one person at all, and it applies to
 * every entry — manual, scheduled, or a loan instalment.
 */
class SecondApproverRule
{
    public const SETTING_KEY = 'accounting.require_second_approver';

    /** True when an entry must be approved by somebody other than its author. */
    public function isRequired(): bool
    {
        return (bool) setting(self::SETTING_KEY);
    }

    /** The mirror image, for call sites that read better in the positive. */
    public function allowsSelfApproval(): bool
    {
        return ! $this->isRequired();
    }

    public function set(bool $required): void
    {
        app(TenantSettings::class)->set(self::SETTING_KEY, $required);
    }

    /**
     * What a company falls back to when it has never chosen — the installation
     * default, from ACCOUNTING_REQUIRE_SECOND_APPROVER in .env.
     */
    public function default(): bool
    {
        return (bool) config(self::SETTING_KEY);
    }
}
