<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
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
    public function __construct(
        private FinancialReportService $reports,
        private JournalEntryService $journalEntries,
    ) {}

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

        // Before the freeze, not after: the closing entry is dated inside the
        // period, and posting into a closed year is refused.
        $closingEntry = $this->postClosingEntry($year, $by);

        $year->forceFill([
            'closed_at' => now(),
            'closed_by' => $by->getKey(),
        ])->save();

        activity('FiscalYear')
            ->performedOn($year)
            ->event('closed')
            ->withProperties([
                'name' => $year->name,
                'closing_entry' => $closingEntry?->entry_number,
            ])
            ->log("Fiscal year {$year->name} closed");

        return $year->refresh();
    }

    /**
     * Roll the year's profit or loss into Retained Earnings.
     *
     * Income and expense accounts measure a single period, so closing zeroes
     * every one of them and books the net to Retained Earnings, which carries
     * forward. Returns null when there was no activity to roll.
     */
    protected function postClosingEntry(FiscalYear $year, User $by): ?JournalEntry
    {
        if ($existing = $this->closingEntry($year)) {
            return $existing;
        }

        $retained = Account::where('code', Account::RETAINED_EARNINGS_CODE)->first();

        if (! $retained) {
            throw new InvalidArgumentException(
                'Account '.Account::RETAINED_EARNINGS_CODE.' (Retained Earnings) is missing, '
                .'so the year\'s profit has nowhere to roll forward to.'
            );
        }

        $lines = [];
        $net = 0.0;

        foreach (Account::whereIn('type', ['income', 'expense'])->orderBy('code')->get() as $account) {
            $movement = round($this->periodMovement($account, $year), 2);

            if (abs($movement) < 0.005) {
                continue;
            }

            // Zeroing an account means posting its balance on the opposite side.
            // `$movement` is signed on the account's normal side, so a negative
            // one (a contra movement) flips back again.
            $normalIsDebit = $account->normal_balance === 'debit';
            $line = ['account_id' => $account->id, 'description' => 'Close '.$account->code.' '.$account->name];

            if (($normalIsDebit && $movement > 0) || (! $normalIsDebit && $movement < 0)) {
                $lines[] = $line + ['credit_amount' => abs($movement)];
            } else {
                $lines[] = $line + ['debit_amount' => abs($movement)];
            }

            // Income adds to profit, expense subtracts.
            $net += $account->type === 'income' ? $movement : -$movement;
        }

        if ($lines === []) {
            return null;
        }

        $net = round($net, 2);

        if (abs($net) >= 0.005) {
            // Profit is a credit to equity; a loss is a debit.
            $lines[] = [
                'account_id' => $retained->id,
                'description' => $net > 0 ? 'Net profit for '.$year->name : 'Net loss for '.$year->name,
            ] + ($net > 0 ? ['credit_amount' => $net] : ['debit_amount' => -$net]);
        }

        $entry = $this->journalEntries->create([
            'entry_date' => $year->end_date->toDateString(),
            'entry_type' => 'closing',
            'memo' => "Year-end close {$year->name}: profit and loss rolled to Retained Earnings",
            'fiscal_year_id' => $year->getKey(),
            'source_type' => FiscalYear::class,
            'source_id' => $year->getKey(),
        ], $lines);

        $entry->update([
            'status' => JournalEntry::STATUS_APPROVED,
            'approved_by' => $by->getKey(),
            'approved_at' => now(),
        ]);

        return $this->journalEntries->post($entry);
    }

    /**
     * The closing entry currently in force for this year, if any.
     *
     * A reversed one does not count. Reversal leaves the original posted (that
     * is the point — the audit trail keeps both), so without this filter a
     * close-reopen-close cycle would find the dead entry, skip the roll-forward
     * and leave the reopened profit sitting in income forever.
     */
    public function closingEntry(FiscalYear $year): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('entry_type', 'closing')
            ->where('source_type', FiscalYear::class)
            ->where('source_id', $year->getKey())
            ->where('is_posted', true)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('journal_entries as reversal')
                    ->whereColumn('reversal.reference', 'journal_entries.entry_number')
                    ->where('reversal.entry_type', 'reversing')
                    ->where('reversal.is_posted', true);
            })
            ->latest('id')
            ->first();
    }

    /**
     * Net movement on an account within the year, signed on its normal side.
     * Only posted entries count.
     */
    protected function periodMovement(Account $account, FiscalYear $year): float
    {
        $query = JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true)
                ->whereDate('entry_date', '>=', $year->start_date->toDateString())
                ->whereDate('entry_date', '<=', $year->end_date->toDateString()));

        $debits = (float) (clone $query)->sum('debit_amount');
        $credits = (float) $query->sum('credit_amount');

        return $account->normal_balance === 'debit'
            ? $debits - $credits
            : $credits - $debits;
    }

    public function reopen(FiscalYear $year, User $by): FiscalYear
    {
        if (! $year->isClosed()) {
            throw new InvalidArgumentException('The year is not closed.');
        }

        // Unfreeze first, so the reversal is allowed to post into the period.
        $year->forceFill(['closed_at' => null, 'closed_by' => null])->save();

        // Undo the roll-forward too. Leaving it in place would keep income and
        // expenses at zero while the year is open again, so the reopened period
        // would report no activity at all.
        $reversal = null;

        if ($closingEntry = $this->closingEntry($year)) {
            $reversal = $this->journalEntries->reverse($closingEntry, $by);
        }

        activity('FiscalYear')
            ->performedOn($year)
            ->event('reopened')
            ->withProperties([
                'name' => $year->name,
                'by' => $by->getKey(),
                'reversed_closing_entry' => $reversal?->entry_number,
            ])
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
