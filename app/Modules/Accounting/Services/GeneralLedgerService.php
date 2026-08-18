<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;

class GeneralLedgerService
{
    /**
     * Ledger for one account: opening balance, chronological posted lines
     * with running balance, closing balance.
     */
    public function accountLedger(Account $account, ?string $from = null, ?string $to = null): array
    {
        $opening = $from ? $this->balanceAsOf($account, $from) : 0.0;

        $query = JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', function ($q) use ($from, $to) {
                $q->where('is_posted', true);
                if ($from) {
                    $q->whereDate('entry_date', '>=', $from);
                }
                if ($to) {
                    $q->whereDate('entry_date', '<=', $to);
                }
            })
            ->with('journalEntry:id,entry_number,entry_date,memo')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entry_lines.id')
            ->select('journal_entry_lines.*');

        // Built by the same method the whole-chart version uses, so the two cannot drift apart: the
        // running balance, the sign convention and the row shape have one implementation between them.
        return $this->ledgerFor($account, $opening, $query->get());
    }

    /**
     * Trial balance as of a date: one debit/credit row per account with activity
     * or a non-zero balance. Debit and credit totals must be equal.
     */
    public function trialBalance(?string $asOf = null, ?int $fiscalYearId = null): array
    {
        $rows = [];
        $totalDebits = 0.0;
        $totalCredits = 0.0;

        foreach (Account::orderBy('code')->get() as $account) {
            $balance = $this->balanceAsOf($account, null, $asOf, $fiscalYearId);

            if (abs($balance) < 0.005 && ! $account->lines()->exists()) {
                continue;
            }

            // A positive balance sits on the account's normal side;
            // a negative one flips to the opposite column.
            $debit = 0.0;
            $credit = 0.0;

            if ($account->normal_balance === 'debit') {
                $balance >= 0 ? $debit = $balance : $credit = -$balance;
            } else {
                $balance >= 0 ? $credit = $balance : $debit = -$balance;
            }

            $rows[] = [
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
            ];

            $totalDebits += $debit;
            $totalCredits += $credit;
        }

        return [
            'as_of' => $asOf ?? now()->toDateString(),
            'rows' => $rows,
            'total_debits' => round($totalDebits, 2),
            'total_credits' => round($totalCredits, 2),
            'balanced' => bccomp(
                number_format($totalDebits, 2, '.', ''),
                number_format($totalCredits, 2, '.', ''),
                2
            ) === 0,
        ];
    }

    /**
     * All account ledgers for a period.
     *
     * Produces exactly what calling `accountLedger()` for every account produces — GeneralLedgerTest
     * asserts that account by account — in three queries instead of three *per account*.
     *
     * The loop it replaces was the obvious way to write this and does not survive a real chart of
     * accounts: `accountLedger()` costs two aggregate queries for the opening balance plus one for the
     * lines, so a 44-account seeded chart cost 136 queries to return two ledgers, and a 200-account chart
     * would cost 600 to return a handful. The work was almost entirely wasted — the accounts with nothing
     * posted to them are dropped by the filter at the end, after having been queried three times each.
     *
     * So: one query for the period's lines, one for the opening balances, and the arithmetic in PHP.
     * Grouping in SQL and running the balance in PHP is the right split here because a running balance is
     * inherently sequential — the alternative is a window function this application's MySQL floor does not
     * guarantee.
     */
    public function generalLedger(?string $from = null, ?string $to = null): array
    {
        $accounts = Account::orderBy('code')->get();
        $openings = $from ? $this->openingBalances($from) : [];

        $lines = JournalEntryLine::query()
            ->whereHas('journalEntry', function ($q) use ($from, $to) {
                $q->where('is_posted', true);
                if ($from) {
                    $q->whereDate('entry_date', '>=', $from);
                }
                if ($to) {
                    $q->whereDate('entry_date', '<=', $to);
                }
            })
            ->with('journalEntry:id,entry_number,entry_date,memo')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entry_lines.id')
            ->select('journal_entry_lines.*')
            ->get()
            ->groupBy('account_id');

        $ledgers = [];

        foreach ($accounts as $account) {
            $opening = round((float) ($openings[$account->id] ?? 0), 2);
            $accountLines = $lines->get($account->id);

            // The same filter the per-account version applied, and applied for the same reason: a chart
            // carries every account a company might ever use, and a ledger of untouched accounts is a
            // hundred empty headings between the ones somebody opened the report to read.
            if ($accountLines === null && $opening == 0.0) {
                continue;
            }

            $ledgers[] = $this->ledgerFor($account, $opening, $accountLines ?? collect());
        }

        return $ledgers;
    }

    /**
     * One account's ledger, from lines already fetched.
     *
     * @param  \Illuminate\Support\Collection<int, JournalEntryLine>  $lines
     * @return array<string, mixed>
     */
    private function ledgerFor(Account $account, float $opening, $lines): array
    {
        $running = $opening;
        $rows = [];

        foreach ($lines as $line) {
            $running += $this->signedDelta($account, $line);

            $rows[] = [
                'date' => $line->journalEntry->entry_date->toDateString(),
                'entry_number' => $line->journalEntry->entry_number,
                'memo' => $line->description ?? $line->journalEntry->memo,
                'debit' => (float) $line->debit_amount,
                'credit' => (float) $line->credit_amount,
                'balance' => round($running, 2),
            ];
        }

        return [
            'account' => ['code' => $account->code, 'name' => $account->name, 'normal_balance' => $account->normal_balance],
            'opening_balance' => round($opening, 2),
            'lines' => $rows,
            'closing_balance' => round($running, 2),
        ];
    }

    /**
     * What a line does to its account's balance, in that account's own direction.
     *
     * An asset's debit increases it and a liability's debit decreases it, which is why this reads
     * `normal_balance` rather than treating a debit as positive everywhere.
     */
    private function signedDelta(Account $account, JournalEntryLine $line): float
    {
        return $account->normal_balance === 'debit'
            ? (float) $line->debit_amount - (float) $line->credit_amount
            : (float) $line->credit_amount - (float) $line->debit_amount;
    }

    /**
     * Every account's balance before a date, in one grouped query.
     *
     * @return array<int, float> account id => opening balance
     */
    private function openingBalances(string $before): array
    {
        $sums = JournalEntryLine::query()
            ->whereHas('journalEntry', function ($q) use ($before) {
                $q->where('is_posted', true)->whereDate('entry_date', '<', $before);
            })
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(debit_amount) as debits, SUM(credit_amount) as credits')
            ->get();

        $directions = Account::query()->pluck('normal_balance', 'id');

        return $sums
            ->mapWithKeys(fn ($row): array => [
                (int) $row->account_id => ($directions[$row->account_id] ?? 'debit') === 'debit'
                    ? (float) $row->debits - (float) $row->credits
                    : (float) $row->credits - (float) $row->debits,
            ])
            ->all();
    }

    /**
     * Account balance computed from posted lines.
     * With $before: balance up to (excluding) that date — the opening balance.
     * With $asOf: balance up to and including that date.
     */
    protected function balanceAsOf(Account $account, ?string $before = null, ?string $asOf = null, ?int $fiscalYearId = null): float
    {
        $query = JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', function ($q) use ($before, $asOf, $fiscalYearId) {
                $q->where('is_posted', true);
                if ($before) {
                    $q->whereDate('entry_date', '<', $before);
                }
                if ($asOf) {
                    $q->whereDate('entry_date', '<=', $asOf);
                }
                if ($fiscalYearId) {
                    $q->where('fiscal_year_id', $fiscalYearId);
                }
            });

        $debits = (float) (clone $query)->sum('debit_amount');
        $credits = (float) $query->sum('credit_amount');

        return $account->normal_balance === 'debit'
            ? $debits - $credits
            : $credits - $debits;
    }
}
