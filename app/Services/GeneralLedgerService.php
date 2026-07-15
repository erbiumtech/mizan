<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;

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

        $running = $opening;
        $rows = [];

        foreach ($query->get() as $line) {
            $delta = $account->normal_balance === 'debit'
                ? (float) $line->debit_amount - (float) $line->credit_amount
                : (float) $line->credit_amount - (float) $line->debit_amount;

            $running += $delta;

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
     * All account ledgers for a period (for export/reporting).
     */
    public function generalLedger(?string $from = null, ?string $to = null): array
    {
        return Account::orderBy('code')->get()
            ->map(fn (Account $a) => $this->accountLedger($a, $from, $to))
            ->filter(fn (array $ledger) => $ledger['lines'] !== [] || $ledger['opening_balance'] != 0)
            ->values()
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
