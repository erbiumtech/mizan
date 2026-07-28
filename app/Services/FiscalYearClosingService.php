<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use InvalidArgumentException;

/**
 * Closing a fiscal year freezes its ledger: once closed, nothing may post into
 * the period (enforced in JournalEntryService::post).
 *
 * A year is only closable when the books actually stand up, and the headline
 * check is Opening Balance Equity. Every opening balance credits that account,
 * so a non-zero balance means the book was only half brought onto the system —
 * and because a single opening entry is valid double-entry, the trial balance
 * happily ties while that is true. Closing over it would freeze a period whose
 * figures are known to be incomplete.
 */
class FiscalYearClosingService
{
    public function __construct(private FinancialReportService $reports) {}

    /**
     * Reasons this year cannot be closed, in the order worth fixing them.
     *
     * @return array<int, string> empty when the year is closable
     */
    public function blockers(FiscalYear $year): array
    {
        if ($year->isClosed()) {
            return ['The year is already closed.'];
        }

        if (! $year->start_date || ! $year->end_date) {
            return ['The year has no start and end date, so its period cannot be determined.'];
        }

        $blockers = [];

        $asOf = $year->end_date->toDateString();
        $report = $this->reports->trialBalance($asOf);

        // The requested guard. Cumulative rather than year-scoped: opening
        // balances are entered once for the whole book, often dated before this
        // year began.
        $openingEquity = $report['opening_balance_equity'];

        if (! $openingEquity['is_clear']) {
            $blockers[] = sprintf(
                'Opening Balance Equity (%s) still holds %s as of %s. Every opening balance credits that account, '
                .'so a leftover means some accounts\' opening figures have not been entered.',
                $openingEquity['code'],
                number_format($openingEquity['balance'], 2),
                $asOf,
            );
        }

        if (! $report['balanced']) {
            $blockers[] = sprintf(
                'The trial balance is out of balance as of %s: debits %s against credits %s.',
                $asOf,
                number_format($report['total_debits'], 2),
                number_format($report['total_credits'], 2),
            );
        }

        if (($unposted = $this->unpostedCount($year)) > 0) {
            $blockers[] = $unposted.' journal '.($unposted === 1 ? 'entry has' : 'entries have')
                .' not been posted in this period. Post or void them first.';
        }

        return $blockers;
    }

    public function canClose(FiscalYear $year): bool
    {
        return $this->blockers($year) === [];
    }

    /**
     * Close the year, or refuse with every reason at once — a caller fixing one
     * problem at a time would otherwise have to retry blindly.
     */
    public function close(FiscalYear $year, User $by): FiscalYear
    {
        $blockers = $this->blockers($year);

        if ($blockers !== []) {
            throw new InvalidArgumentException(
                'This fiscal year cannot be closed yet: '.implode(' ', $blockers)
            );
        }

        $year->forceFill([
            'closed_at' => now(),
            'closed_by' => $by->getKey(),
        ])->save();

        activity('FiscalYear')
            ->performedOn($year)
            ->event('closed')
            ->withProperties(['name' => $year->name])
            ->log("Fiscal year {$year->name} closed");

        return $year->refresh();
    }

    public function reopen(FiscalYear $year, User $by): FiscalYear
    {
        if (! $year->isClosed()) {
            throw new InvalidArgumentException('The year is not closed.');
        }

        $year->forceFill(['closed_at' => null, 'closed_by' => null])->save();

        activity('FiscalYear')
            ->performedOn($year)
            ->event('reopened')
            ->withProperties(['name' => $year->name, 'by' => $by->getKey()])
            ->log("Fiscal year {$year->name} reopened");

        return $year->refresh();
    }

    /**
     * Entries dated in the period that never reached the ledger. Rejected ones
     * do not count — they are deliberately dead, not pending work.
     */
    protected function unpostedCount(FiscalYear $year): int
    {
        return JournalEntry::query()
            ->where('is_posted', false)
            ->whereNot('status', JournalEntry::STATUS_REJECTED)
            ->whereDate('entry_date', '>=', $year->start_date->toDateString())
            ->whereDate('entry_date', '<=', $year->end_date->toDateString())
            ->count();
    }
}
