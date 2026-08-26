<?php

namespace App\Modules\Accounting\Reporting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Support\Reporting\Dataset;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\DatasetFilter;

/**
 * Journal lines — `docs/reports-expansion-plan.md` Phase 6, item 1.
 *
 * The first subject the plan names, and the one every other ledger report in this application is a shaped
 * view of: a trial balance is these lines grouped by account, a general ledger is them ordered by date within
 * an account, an account register is them for one account with a running balance.
 *
 * **The line has no date, and that shapes the whole declaration.** `journal_entry_lines` has never carried
 * one — the date is on the entry, because a journal entry is one event with two or more sides, and putting a
 * date on each side would be the same fact stored twice with no constraint keeping the copies equal. So the
 * period is reached through `journalEntry`, which is a `whereHas` rather than a join, and the entry's date is
 * a related column here: displayable, not groupable.
 *
 * **Which means "journal lines by month" is not a report this subject can build, and that is correct.**
 * Item 7: "a question that needs two subjects joined is a coded report. The builder's answer to it is a clear
 * refusal, not a join it cannot secure." Grouping by the entry's month needs `journal_entries` in the GROUP
 * BY, which needs a join; the general ledger is the coded report that already does it. What this subject
 * groups on is the account, which is a column it owns and the axis every ledger summary actually uses.
 *
 * **Posted-only is not applied here.** A trial balance reads posted entries; this dataset reads every line,
 * because "what is sitting unposted in this account" is a real question and one nothing else answers. The
 * entry's status is a related column so somebody can put it in the report and see which is which.
 */
class JournalLineDataset extends Dataset
{
    public static function label(): string
    {
        return 'Journal lines';
    }

    public static function description(): string
    {
        return 'One side of a journal entry: an account, a debit or a credit. Every ledger figure is these lines.';
    }

    public static function model(): string
    {
        return JournalEntryLine::class;
    }

    public static function permission(): string
    {
        return 'JournalEntryView';
    }

    public static function periodColumn(): ?string
    {
        return 'journalEntry.entry_date';
    }

    public static function columns(): array
    {
        return [
            DatasetColumn::related('entry_date', 'Date', 'journalEntry.entry_date', DatasetColumn::DATE),
            DatasetColumn::related('entry_number', 'Entry', 'journalEntry.entry_number'),
            DatasetColumn::related('reference', 'Ref', 'journalEntry.reference'),
            DatasetColumn::related('entry_status', 'Status', 'journalEntry.status'),

            // Grouped on the local key, printed as the name — the pattern the whole registry leans on. Both
            // columns group on `account_id`, so a report may show the code, the name or both and still
            // bucket correctly.
            DatasetColumn::related('account', 'Account', 'account.name', groupBy: 'account_id'),
            DatasetColumn::related('account_code', 'Code', 'account.code', groupBy: 'account_id'),
            DatasetColumn::related('account_type', 'Account type', 'account.type', groupBy: 'account_id'),

            DatasetColumn::make('debit', 'Debit', DatasetColumn::MONEY, 'debit_amount'),
            DatasetColumn::make('credit', 'Credit', DatasetColumn::MONEY, 'credit_amount'),

            /*
             * The line as one signed figure.
             *
             * Derived rather than a column, because there is no signed column to name: the ledger stores the
             * two sides separately, which is what makes an entry checkable. A report that wants a column it
             * can add down uses this; one that wants to prove the entry balances uses the two above.
             */
            DatasetColumn::derived(
                'signed_amount',
                'Amount',
                fn (JournalEntryLine $line): float => (float) $line->debit_amount - (float) $line->credit_amount,
                DatasetColumn::MONEY,
            ),

            DatasetColumn::make('description', 'Description', groupable: false),
            DatasetColumn::make('reconciled_at', 'Reconciled', DatasetColumn::DATE, groupable: false),
        ];
    }

    public static function filters(): array
    {
        return [
            DatasetFilter::dateRange('period', 'Entry date', 'journalEntry.entry_date'),
            DatasetFilter::select(
                'account',
                'Account',
                'account_id',
                // Through the model, so the company's own chart and nothing else. Ordered by code because
                // that is the order an accountant reads a chart of accounts in.
                fn (): array => Account::query()->orderBy('code')
                    ->pluck('name', 'id')
                    ->all(),
            ),
            DatasetFilter::search('description', 'Description contains', ['description']),
        ];
    }
}
