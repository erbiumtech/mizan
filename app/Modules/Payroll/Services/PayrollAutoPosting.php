<?php

namespace App\Modules\Payroll\Services;

use App\Support\TenantSettings;

/**
 * Whether a payslip's journal entry posts itself, per company.
 *
 * One place for the setting key, which was written out as a string in three: the branch in
 * PayrollPostingService that reads it, the Company Settings toggle that writes it, and the
 * config default behind both.
 *
 * What the flag decides, so the choice is not made from its name alone: **on**, a payslip's
 * entry is approved and posted as it is created, with no `approved_by` — nobody signs off,
 * and the balances are always current. **Off**, the entry is created as `pending_approval` and
 * a Manager or CEO has to approve and post it, which is real segregation of duties and also
 * how a month's payroll ends up accrued nowhere while its payments are posted (see
 * PendingPayrollPoster).
 */
class PayrollAutoPosting
{
    public const SETTING_KEY = 'accounting.auto_post_payroll';

    public function isEnabled(): bool
    {
        return (bool) setting(self::SETTING_KEY);
    }

    /**
     * Store the choice for the current company.
     *
     * Written through TenantSettings, the same path the Company Settings screen uses, so the
     * toggle there reflects a change made from the console.
     */
    public function set(bool $enabled): void
    {
        app(TenantSettings::class)->set(self::SETTING_KEY, $enabled);
    }

    /**
     * The shipped default, for reporting what a company falls back to when it has never
     * chosen — which is what `setting()` returns for it.
     */
    public function default(): bool
    {
        return (bool) config(self::SETTING_KEY);
    }
}
