<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Support\LedgerControls;

/**
 * Do the receivables in the ledger and the receivables in the invoices agree? — `docs/erpnext-gap-plan.md`
 * Phase 2, item 1.
 *
 * Both figures already existed and nothing compared them. The ageing reports read `Invoice` rows; the trial
 * balance reads the control accounts. They are the same money by two routes, and the routes can diverge —
 * the ordinary way being a journal entry posted straight at Receivables, which every ageing report in this
 * application is blind to.
 *
 * **In Invoicing, because Invoicing owns both sides.** It raises the invoices *and* it posts to 1250 and
 * 2400 — `InvoiceService` names those accounts itself — so this is the one module that can compare them
 * without reaching anywhere new. Accounting is a declared requirement of this module, so reading the ledger
 * here costs no boundary.
 */
class ControlReconciliation
{
    /** The control accounts, which are the ones `InvoiceService` posts a document's total to. */
    public const RECEIVABLES = '1250';

    public const PAYABLES = '2400';

    /**
     * Contribute both controls to the registry the health check reads.
     *
     * Called from the service provider so the pair is registered at boot, whether or not anything has asked
     * for a report yet — the same reason `CashCommitmentReports::registerSources()` is called there.
     */
    public static function register(): void
    {
        $invoices = fn (): InvoiceService => app(InvoiceService::class);

        LedgerControls::register(
            'Receivables',
            fn (): float => app(self::class)->controlBalance(self::RECEIVABLES),
            fn (): float => (float) $invoices()->outstandingReceivables()['total'],
            'A journal entry posted straight at 1250 moves the ledger and no invoice, so it is invisible to '
            .'Aged Receivables. Look for entries against 1250 with no invoice behind them.',
        );

        LedgerControls::register(
            'Payables',
            fn (): float => app(self::class)->controlBalance(self::PAYABLES),
            fn (): float => (float) $invoices()->outstandingPayables()['total'],
            'A journal entry posted straight at 2400 moves the ledger and no bill. Look for entries against '
            .'2400 with no purchase invoice behind them.',
        );
    }

    /**
     * A control account's balance from posted entries, signed the way its own side of the sheet reads.
     *
     * The same arithmetic as `FinancialReportService::periodBalance()` and `GeneralLedgerService`: a
     * debit-normal account is debits less credits and a credit-normal one the other way about. Receivables
     * is an asset and Payables a liability, so both come back positive when the company is owed money and
     * owes money respectively — which is the sign the ageing totals use, or the comparison would report
     * every company as broken by exactly twice its payables.
     */
    public function controlBalance(string $code): float
    {
        $account = Account::query()->where('code', $code)->first();

        if ($account === null) {
            return 0.0;
        }

        $query = JournalEntryLine::query()
            ->where('account_id', $account->getKey())
            ->whereHas('journalEntry', fn ($entry) => $entry->where('is_posted', true));

        $debits = (float) (clone $query)->sum('debit_amount');
        $credits = (float) $query->sum('credit_amount');

        return round($account->normal_balance === 'debit' ? $debits - $credits : $credits - $debits, 2);
    }
}
