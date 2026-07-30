<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;

class FinancialReportService
{
    public function __construct(private GeneralLedgerService $ledger) {}

    /**
     * Trial balance as of a date, grouped by account type with grand totals.
     * Delegates the row computation to GeneralLedgerService::trialBalance()
     * so there is a single source of truth for balances.
     */
    public function trialBalance(?string $asOf = null, ?int $fiscalYearId = null): array
    {
        $report = $this->ledger->trialBalance($asOf, $fiscalYearId);

        $sections = [];

        foreach ($report['rows'] as $row) {
            $sections[$row['type']]['type'] = $row['type'];
            $sections[$row['type']]['rows'][] = $row;
            $sections[$row['type']]['total_debits'] = round(($sections[$row['type']]['total_debits'] ?? 0) + $row['debit'], 2);
            $sections[$row['type']]['total_credits'] = round(($sections[$row['type']]['total_credits'] ?? 0) + $row['credit'], 2);
        }

        return [
            'as_of' => $report['as_of'],
            'fiscal_year_id' => $fiscalYearId,
            'sections' => array_values($sections),
            'total_debits' => $report['total_debits'],
            'total_credits' => $report['total_credits'],
            'balanced' => $report['balanced'],
            'opening_balance_equity' => $this->openingBalanceEquity($report['rows']),
        ];
    }

    /**
     * State of the Opening Balance Equity account.
     *
     * A trial balance can be perfectly in balance and still be wrong in a
     * specific, common way: only some accounts' opening balances were entered.
     * Because every opening entry credits this one account, the leftover shows
     * up here rather than as an imbalance. Reporting it separately makes a
     * half-migrated book visible.
     *
     * @param  array<int, array<string, mixed>>  $rows  trial balance rows
     * @return array{code: string, balance: float, is_clear: bool, in_use: bool}
     */
    protected function openingBalanceEquity(array $rows): array
    {
        $code = Account::OPENING_BALANCE_EQUITY_CODE;

        $row = collect($rows)->firstWhere('code', $code);

        // Absent from the rows means the account has no postings at all — no
        // opening balances have been entered, which is a clear state, not a
        // problem to flag.
        $balance = $row
            ? round((float) $row['credit'] - (float) $row['debit'], 2)
            : 0.0;

        return [
            'code' => $code,
            // Signed on the account's normal (credit) side: positive means the
            // credits from opening entries have not been fully offset.
            'balance' => $balance,
            'is_clear' => abs($balance) < 0.005,
            'in_use' => $row !== null,
        ];
    }

    /**
     * Profit & loss for a period: income minus expenses from posted entries.
     * Contra accounts respect normal_balance; only posted entries count.
     */
    public function profitAndLoss(?string $from = null, ?string $to = null, ?int $fiscalYearId = null): array
    {
        $income = $this->section('income', $from, $to, $fiscalYearId);
        $expenses = $this->section('expense', $from, $to, $fiscalYearId);

        $netProfit = round($income['total'] - $expenses['total'], 2);

        return [
            'from' => $from,
            'to' => $to ?? now()->toDateString(),
            'fiscal_year_id' => $fiscalYearId,
            'income' => $income,
            'expenses' => $expenses,
            'net_profit' => $netProfit,
            'is_profit' => $netProfit >= 0,
        ];
    }

    /**
     * All accounts of one type with their period activity (posted lines only).
     */
    protected function section(string $type, ?string $from, ?string $to, ?int $fiscalYearId): array
    {
        $rows = [];
        $total = 0.0;

        foreach (Account::ofType($type)->orderBy('code')->get() as $account) {
            $amount = $this->periodBalance($account, $from, $to, $fiscalYearId);

            if (abs($amount) < 0.005) {
                continue;
            }

            $rows[] = [
                'code' => $account->code,
                'name' => $account->name,
                'amount' => round($amount, 2),
            ];

            $total += $amount;
        }

        return [
            'rows' => $rows,
            'total' => round($total, 2),
        ];
    }

    /**
     * Net movement on an account over the period, signed by normal_balance
     * (income: credits − debits; expense: debits − credits).
     */
    protected function periodBalance(Account $account, ?string $from, ?string $to, ?int $fiscalYearId): float
    {
        $query = JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', function ($q) use ($from, $to, $fiscalYearId) {
                $q->where('is_posted', true);
                if ($from) {
                    $q->whereDate('entry_date', '>=', $from);
                }
                if ($to) {
                    $q->whereDate('entry_date', '<=', $to);
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
