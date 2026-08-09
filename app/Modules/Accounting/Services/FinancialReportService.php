<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use Illuminate\Support\Carbon;

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
     * Statement of position: what the company owns, owes, and what is left over.
     *
     * Built from the trial balance rather than from the ledger again, so the two
     * cannot disagree — which is the property that makes a balance sheet worth
     * printing.
     *
     * Earnings not yet closed are shown as their own equity line. Income and
     * expense accounts measure a period and are zeroed into Retained Earnings only
     * at year-end (FiscalYearClosingService), so between closes the profit so far
     * sits in those accounts and nowhere else — omit it and assets exceed
     * liabilities plus equity by exactly the profit. Taken as the cumulative
     * income less expense balance, which needs no knowledge of which years are
     * closed: a closed year's accounts are already zero.
     */
    public function balanceSheet(?string $asOf = null, ?int $fiscalYearId = null): array
    {
        $report = $this->ledger->trialBalance($asOf, $fiscalYearId);

        $assets = $this->positionSection($report['rows'], 'asset');
        $liabilities = $this->positionSection($report['rows'], 'liability');
        $equity = $this->positionSection($report['rows'], 'equity');

        $earnings = round(
            $this->positionSection($report['rows'], 'income')['total']
            - $this->positionSection($report['rows'], 'expense')['total'],
            2
        );

        $equityTotal = round($equity['total'] + $earnings, 2);
        $fundingTotal = round($liabilities['total'] + $equityTotal, 2);

        return [
            'as_of' => $report['as_of'],
            'fiscal_year_id' => $fiscalYearId,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'retained_earnings_for_period' => $earnings,
            'equity_total' => $equityTotal,
            'liabilities_and_equity_total' => $fundingTotal,
            'balanced' => bccomp(
                number_format($assets['total'], 2, '.', ''),
                number_format($fundingTotal, 2, '.', ''),
                2
            ) === 0,
            // The same half-opened-book warning the trial balance carries: every
            // opening entry credits this one account, so a leftover balance means
            // only some accounts were opened.
            'opening_balance_equity' => $this->openingBalanceEquity($report['rows']),
        ];
    }

    /** Which side each section of a statement is measured from. */
    private const SECTION_SIDE = [
        'asset' => 'debit',
        'expense' => 'debit',
        'liability' => 'credit',
        'equity' => 'credit',
        'income' => 'credit',
    ];

    /**
     * One section of the statement, with accounts that hold nothing left out.
     *
     * Signed by the section, not by the account. An account whose balance sits
     * against its section shows as a negative and *reduces* the section, which is
     * what a contra account is for: 1500 Accumulated Depreciation is an asset with
     * a credit balance, and it belongs in assets as a deduction. Signing it by its
     * own normal balance would add 20,000 of depreciation to what the company owns.
     *
     * It is also what keeps the statement in balance. Debits less credits across
     * every account is zero, so assets less liabilities less equity less income
     * plus expenses is zero — the identity only holds while each section is
     * measured from its own side.
     *
     * @param  array<int, array<string, mixed>>  $rows  trial balance rows
     * @return array{rows: array<int, array{code: string, name: string, amount: float}>, total: float}
     */
    protected function positionSection(array $rows, string $type): array
    {
        $section = [];
        $total = 0.0;

        foreach ($rows as $row) {
            if ($row['type'] !== $type) {
                continue;
            }

            $amount = (self::SECTION_SIDE[$type] ?? 'debit') === 'debit'
                ? round($row['debit'] - $row['credit'], 2)
                : round($row['credit'] - $row['debit'], 2);

            if (abs($amount) < 0.005) {
                continue;
            }

            $section[] = ['code' => $row['code'], 'name' => $row['name'], 'amount' => $amount];
            $total += $amount;
        }

        return ['rows' => $section, 'total' => round($total, 2)];
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

    /** Accounts holding cash: the same definition CashFlowChart uses. */
    private const CASH_CODE_PREFIX = '11';

    /**
     * Fixed-asset cost accounts. Money spent here is investing, not operating.
     *
     * Accumulated depreciation is deliberately not in this range even though it is
     * a 15xx asset: depreciation moves no cash, and it belongs in operating as the
     * add-back that undoes its effect on net income.
     */
    private const INVESTING_CODE_PREFIX = '14';

    private const DEPRECIATION_CODE = '1500';

    /**
     * Cash flow for a period, indirect method.
     *
     * Built so that it cannot fail to tie. Debits less credits across every account
     * is zero, so the cash effect of a period is exactly the negative of every
     * non-cash account's movement — which means classifying each non-cash account
     * into operating, investing or financing exactly once produces three subtotals
     * that must sum to the change in cash. No line is estimated and nothing is left
     * over.
     *
     * Net income appears as one line because the income and expense accounts'
     * combined movement *is* net income: credits less debits over those accounts.
     */
    public function cashFlow(?string $from = null, ?string $to = null, ?int $fiscalYearId = null): array
    {
        $operating = ['net_income' => 0.0, 'depreciation' => 0.0, 'working_capital' => []];
        $investing = [];
        $financing = [];
        $netChange = 0.0;

        foreach (Account::orderBy('code')->get() as $account) {
            // Signed on the account's normal side; the cash effect of a movement on
            // a non-cash account is the opposite of debits less credits.
            $signed = $this->periodBalance($account, $from, $to, $fiscalYearId);
            $debitLessCredit = $account->normal_balance === 'debit' ? $signed : -$signed;

            if (abs($debitLessCredit) < 0.005) {
                continue;
            }

            if (str_starts_with($account->code, self::CASH_CODE_PREFIX)) {
                $netChange = round($netChange + $debitLessCredit, 2);

                continue;
            }

            $effect = round(-$debitLessCredit, 2);
            $line = ['code' => $account->code, 'name' => $account->name, 'amount' => $effect];

            match (true) {
                in_array($account->type, ['income', 'expense'], true) => $operating['net_income'] = round($operating['net_income'] + $effect, 2),
                $account->code === self::DEPRECIATION_CODE => $operating['depreciation'] = round($operating['depreciation'] + $effect, 2),
                str_starts_with($account->code, self::INVESTING_CODE_PREFIX) => $investing[] = $line,
                $account->type === 'equity' => $financing[] = $line,
                default => $operating['working_capital'][] = $line,
            };
        }

        $operatingTotal = round(
            $operating['net_income'] + $operating['depreciation']
            + array_sum(array_column($operating['working_capital'], 'amount')),
            2
        );
        $investingTotal = round(array_sum(array_column($investing, 'amount')), 2);
        $financingTotal = round(array_sum(array_column($financing, 'amount')), 2);

        $opening = $from ? $this->cashAt(Carbon::parse($from)->subDay()->toDateString(), $fiscalYearId) : 0.0;

        return [
            'from' => $from,
            'to' => $to ?? now()->toDateString(),
            'operating' => $operating + ['total' => $operatingTotal],
            'investing' => ['rows' => $investing, 'total' => $investingTotal],
            'financing' => ['rows' => $financing, 'total' => $financingTotal],
            'net_change' => $netChange,
            'opening_cash' => $opening,
            'closing_cash' => round($opening + $netChange, 2),
            // The three sections must account for the whole movement in cash. They
            // cannot fail to unless an account escaped classification, which is why
            // this is reported rather than assumed.
            'reconciles' => bccomp(
                number_format(round($operatingTotal + $investingTotal + $financingTotal, 2), 2, '.', ''),
                number_format($netChange, 2, '.', ''),
                2
            ) === 0,
        ];
    }

    /** Cash and bank on a date, from the same ledger the balance sheet reads. */
    public function cashAt(?string $asOf = null, ?int $fiscalYearId = null): float
    {
        $rows = $this->ledger->trialBalance($asOf, $fiscalYearId)['rows'];

        $cash = array_filter(
            $rows,
            fn (array $row): bool => str_starts_with($row['code'], self::CASH_CODE_PREFIX),
        );

        return round(array_sum(array_map(
            fn (array $row): float => $row['debit'] - $row['credit'],
            $cash,
        )), 2);
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
