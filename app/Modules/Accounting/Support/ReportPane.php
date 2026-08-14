<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\BudgetReportService;
use App\Modules\Accounting\Services\ContractorPaymentSummary;
use App\Modules\Accounting\Services\CurrencyRevaluationService;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Accounting\Services\PettyCashService;
use App\Modules\Accounting\Services\RegisterEntryService;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Invoicing\Services\FbrReconciliation;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\SalaryBankExportService;
use App\Modules\Payroll\Services\WithholdingTaxSummary;
use Carbon\Carbon;

/**
 * What the explorer's right-hand pane draws, for any report.
 *
 * ComparativeStatement handles the two reports shaped like a statement. This is the layer above it: it
 * answers "what does this report look like" for everything in the hub, and the answer is one of four
 * kinds, because the reports genuinely are four different things.
 *
 *   - **statement** — named sections of accounts, one amount each, a total per section, and a prior-year
 *     column. The balance sheet, the profit and loss, and the cash flow.
 *   - **ledger** — an account per row with a debit *and* a credit. The trial balance, which cannot be
 *     squeezed into one amount column without choosing a side for it and calling that the figure.
 *   - **table** — columns and rows that are not accounts: ageing buckets by invoice, tax by employee.
 *   - **file** — a report whose output is a file rather than a page. These get a summary of what the file
 *     would contain — how many rows, for what period, totalling what — and the download itself stays on
 *     the report's own screen, where the confirmation and the release state live.
 *
 * Every report in the hub is drawn here. Three of them need something the date cannot supply — the
 * account register needs an account, find-transactions needs a search, budget-vs-actual needs a budget —
 * so the pane asks for it and passes it in; `ASKS` says which and what. Everything else derives what it
 * needs from the date: the fiscal year containing it, or its month.
 */
class ReportPane
{
    public function __construct(
        private ComparativeStatement $statements,
        private FinancialReportService $reports,
    ) {}

    /**
     * Which of the four kinds a report is, or null when it needs input first.
     *
     * Keyed on the Reports hub's own catalogue keys (class basenames).
     */
    public const KINDS = [
        'BalanceSheet' => 'statement',
        'ProfitAndLoss' => 'statement',
        'CashFlow' => 'statement',
        'TrialBalance' => 'ledger',
        'AgedReceivables' => 'table',
        'AgedPayables' => 'table',
        'TaxSummary' => 'table',
        'ContractorPayments' => 'table',
        'BudgetVsActual' => 'table',
        'FbrInvoiceReporting' => 'table',
        'PettyCashBook' => 'table',
        'CurrencyRevaluation' => 'table',
        'AccountRegister' => 'table',
        'FindTransactions' => 'table',
        'SalaryBankFile' => 'file',
        'FbrTaxFile' => 'file',
        'BankPaymentFile' => 'file',
    ];

    /**
     * Extra state a report needs beyond the date, and what the pane must offer to collect it.
     *
     * The account register is meaningless without an account and find-transactions without something to
     * find, so the pane asks — which is the difference between a report it can draw and one it cannot.
     * Everything else derives what it needs from the date: the fiscal year containing it, or its month.
     */
    public const ASKS = [
        'AccountRegister' => ['account'],
        'FindTransactions' => ['search'],
        'BudgetVsActual' => ['budget'],
        // Both of these are filed monthly as well as read for the year, so the month is a filter rather
        // than a derived period: "the whole year" is a legitimate answer and the pane has to allow it.
        'TaxSummary' => ['month'],
        'PettyCashBook' => ['month'],
    ];

    /** @return array<int, string> the controls the pane must offer for a report */
    public static function asks(?string $key): array
    {
        return self::ASKS[$key] ?? [];
    }

    public static function kindFor(?string $key): ?string
    {
        return self::KINDS[$key] ?? null;
    }

    public static function supports(?string $key): bool
    {
        return self::kindFor($key) !== null;
    }

    /**
     * @param  array<string, mixed>  $asked  what the pane's filter bar collected for the reports that ask:
     *                                       an account id, a budget id, a search term, a month
     * @return array<string, mixed>|null null when the report needs input before it can be drawn
     */
    public function for(string $key, string $asOf, bool $comparison = true, array $asked = []): ?array
    {
        return match ($key) {
            'BalanceSheet', 'ProfitAndLoss', 'CashFlow' => $this->statement($key, $asOf, $comparison),
            'TrialBalance' => $this->trialBalance($asOf),
            'AgedReceivables', 'AgedPayables' => $this->ageing($key, $asOf),
            'TaxSummary' => $this->taxSummary($asOf, $asked['month'] ?? null),
            'ContractorPayments' => $this->contractorPayments($asOf),
            'BudgetVsActual' => $this->budgetVsActual($asOf, $asked['budget'] ?? null),
            'FbrInvoiceReporting' => $this->fbrReconciliation($asOf),
            'PettyCashBook' => $this->pettyCash($asOf, $asked['month'] ?? null),
            'CurrencyRevaluation' => $this->revaluation($asOf),
            'AccountRegister' => $this->accountRegister($asOf, $asked['account'] ?? null),
            'FindTransactions' => $this->findTransactions($asOf, (string) ($asked['search'] ?? '')),
            'SalaryBankFile', 'FbrTaxFile', 'BankPaymentFile' => $this->file($key, $asOf),
            default => null,
        };
    }

    /**
     * The options behind one of a report's pickers.
     *
     * @return array<int|string, string> value => label, empty for a filter that is typed rather than picked
     */
    public function options(string $key, ?string $ask = null, ?string $asOf = null): array
    {
        // Defaults to the report's first filter, so a report with one of them can be asked without
        // naming it — which is every caller but the pane's own filter bar.
        $ask ??= self::asks($key)[0] ?? null;

        return match ($ask) {
            'account' => app(RegisterEntryService::class)->registerAccounts()
                ->mapWithKeys(fn (Account $account): array => [$account->getKey() => $account->code.' '.$account->name])
                ->all(),
            'budget' => Budget::query()
                ->orderByDesc('id')
                ->get()
                ->mapWithKeys(fn (Budget $budget): array => [$budget->getKey() => $budget->name ?? ('Budget '.$budget->getKey())])
                ->all(),
            // Keyed by name because that is what a payslip's month is stored as, and in fiscal order
            // because that is the order these are filed in. See ReportPeriod::months().
            'month' => collect(ReportPeriod::months($asOf ?? now()->toDateString()))
                ->mapWithKeys(fn (string $month): array => [$month => $month])
                ->all(),
            default => [],
        };
    }

    // ------------------------------------------------------------------ statements

    /** @return array<string, mixed>|null */
    private function statement(string $key, string $asOf, bool $comparison): ?array
    {
        if ($key === 'CashFlow') {
            return $this->cashFlow($asOf, $comparison);
        }

        $statement = $this->statements->for($key, $asOf, $comparison);

        return $statement === null
            ? null
            : ['kind' => 'statement', 'drillable' => $this->drillable(), ...$statement];
    }

    /**
     * The cash flow, as a statement of three sections.
     *
     * Its operating section is built from net income and working-capital movements rather than from a
     * list of accounts, so the rows here are those movements — which is what the report's own page shows
     * too. The comparison is the same range a year earlier, as everywhere else.
     *
     * @return array<string, mixed>
     */
    private function cashFlow(string $asOf, bool $comparison): array
    {
        // The financial year to date, not the calendar year — see ReportPeriod.
        $from = ReportPeriod::toDate($asOf)['from'];
        $current = $this->reports->cashFlow($from, $asOf);

        ['from' => $priorFrom, 'to' => $priorTo] = ReportPeriod::previous($from, $asOf);
        $previous = $comparison ? $this->reports->cashFlow($priorFrom, $priorTo) : null;

        $sections = [];

        foreach (['operating' => 'OPERATING', 'investing' => 'INVESTING', 'financing' => 'FINANCING'] as $part => $label) {
            $sections[] = $this->cashFlowSection($label, $current[$part] ?? [], $previous[$part] ?? null);
        }

        $net = (float) ($current['net_movement'] ?? 0);

        return [
            'kind' => 'statement',
            'key' => 'CashFlow',
            'title' => 'Cash Flow',
            'subtitle' => $this->subtitle(Carbon::parse($from)->format('j M Y').' to '.Carbon::parse($asOf)->format('j M Y')),
            'current_label' => Carbon::parse($asOf)->format('j M Y'),
            'previous_label' => $comparison ? Carbon::parse($asOf)->subYear()->format('j M Y') : null,
            'sections' => $sections,
            'tiles' => [
                ['label' => 'NET MOVEMENT', 'value' => $net, 'accent' => true],
                ['label' => 'CASH AT END', 'value' => (float) ($current['closing_cash'] ?? $current['cash_at_end'] ?? 0), 'accent' => false],
            ],
            'note' => $net >= 0 ? 'CASH INCREASED OVER THE PERIOD' : 'CASH FELL OVER THE PERIOD',
            'balanced' => true,
            'closing' => [
                'label' => 'Net movement in cash',
                'current' => $net,
                'previous' => $previous === null ? null : (float) ($previous['net_movement'] ?? 0),
            ],
        ];
    }

    /**
     * One cash-flow section. Its rows are movements, which may be a flat list or the operating
     * section's own structure, so anything that is not a list of {name, amount} is skipped rather than
     * guessed at.
     *
     * @return array<string, mixed>
     */
    private function cashFlowSection(string $label, mixed $current, mixed $previous): array
    {
        $rows = [];
        $total = 0.0;

        foreach ($this->movements($current) as $code => $movement) {
            $rows[] = [
                'code' => (string) $code,
                'label' => $movement['name'],
                'current' => $movement['amount'],
                'previous' => $this->movements($previous)[$code]['amount'] ?? null,
                'change' => null,
            ];

            $total += $movement['amount'];
        }

        return [
            'label' => $label,
            'rows' => $rows,
            'total' => [
                'label' => 'Total '.mb_strtolower($label),
                'current' => round($total, 2),
                'previous' => $previous === null ? null : round(array_sum(array_column($this->movements($previous), 'amount')), 2),
                'change' => null,
            ],
        ];
    }

    /**
     * Flattens a cash-flow section into {code => [name, amount]}.
     *
     * The operating section is an array of named figures (net income, depreciation) plus a nested list of
     * working-capital movements; investing and financing are flat lists of account lines. Both are walked
     * here rather than in the view.
     *
     * @return array<string, array{name: string, amount: float}>
     */
    private function movements(mixed $section): array
    {
        $out = [];

        foreach ((array) $section as $key => $value) {
            if (is_array($value) && array_key_exists('name', $value) && array_key_exists('amount', $value)) {
                $out[$value['code'] ?? $key] = ['name' => $value['name'], 'amount' => (float) $value['amount']];

                continue;
            }

            // A nested list — the working-capital movements.
            if (is_array($value)) {
                foreach ($this->movements($value) as $code => $movement) {
                    $out[$code] = $movement;
                }

                continue;
            }

            if (is_numeric($value)) {
                $out[$key] = ['name' => ucfirst(str_replace('_', ' ', (string) $key)), 'amount' => (float) $value];
            }
        }

        return $out;
    }

    // --------------------------------------------------------------- trial balance

    /**
     * The trial balance: every account with its debit and its credit, and the proof it adds up.
     *
     * Deliberately no comparison column. The report's claim is that this period's debits equal its
     * credits; a prior year beside it would double the columns and answer a question nobody asks of a
     * trial balance.
     *
     * @return array<string, mixed>
     */
    private function trialBalance(string $asOf): array
    {
        $report = $this->reports->trialBalance($asOf);

        $sections = [];

        foreach ($report['sections'] as $section) {
            $sections[] = [
                'label' => mb_strtoupper($section['type']),
                'rows' => array_map(fn (array $row): array => [
                    'code' => $row['code'],
                    'label' => $row['name'],
                    'debit' => (float) $row['debit'],
                    'credit' => (float) $row['credit'],
                ], $section['rows']),
                'total' => [
                    'label' => 'Total '.mb_strtolower($section['type']),
                    'debit' => (float) $section['total_debits'],
                    'credit' => (float) $section['total_credits'],
                ],
            ];
        }

        return [
            'kind' => 'ledger',
            'drillable' => $this->drillable(),
            'key' => 'TrialBalance',
            'title' => 'Trial Balance',
            'subtitle' => $this->subtitle('as of '.Carbon::parse($asOf)->format('j M Y')),
            'columns' => ['Account', 'Debit', 'Credit'],
            'sections' => $sections,
            'tiles' => [
                ['label' => 'TOTAL DEBITS', 'value' => (float) $report['total_debits'], 'accent' => false],
                ['label' => 'TOTAL CREDITS', 'value' => (float) $report['total_credits'], 'accent' => false],
            ],
            'note' => $report['balanced'] ? 'BALANCED · DEBITS = CREDITS' : 'OUT OF BALANCE',
            'balanced' => (bool) $report['balanced'],
        ];
    }

    // ----------------------------------------------------------------- the ageing

    /**
     * Receivables or payables, bucketed by how late they are.
     *
     * The buckets are the report; the invoice list underneath is what makes a bucket actionable, and it
     * is capped here because the pane is a reading surface rather than a work queue — the report's own
     * page has all of them.
     *
     * @return array<string, mixed>
     */
    private function ageing(string $key, string $asOf): array
    {
        $receivable = $key === 'AgedReceivables';

        $report = $receivable
            ? app(InvoiceService::class)->outstandingReceivables($asOf)
            : app(InvoiceService::class)->outstandingPayables($asOf);

        // Every outstanding invoice, oldest first. Capped at twenty-five once and that was the wrong
        // call: the reason to open an ageing report is to work through it, and a list that stops at
        // twenty-five silently omits the invoices at the end — which on this report are the worst ones.
        $invoices = collect($report['invoices'])
            ->sortByDesc('days_overdue')
            ->values();

        return [
            'kind' => 'table',
            'key' => $key,
            'title' => $receivable ? 'Aged Receivables' : 'Aged Payables',
            'subtitle' => $this->subtitle('as of '.Carbon::parse($report['as_of'])->format('j M Y')),
            'columns' => ['Invoice', 'Contact', 'Days overdue', 'Outstanding'],
            'grid' => '8rem minmax(0, 1fr) 7rem 8rem',
            'numeric' => [2, 3],
            'empty' => 'Nothing is outstanding at this date.',
            'rows' => $invoices->map(fn (array $invoice): array => [
                $invoice['invoice_number'],
                $invoice['contact'],
                (string) $invoice['days_overdue'],
                number_format($invoice['outstanding_base'], 0),
            ])->all(),
            // The buckets as tiles: what the report is actually for.
            'tiles' => collect($report['buckets'])
                ->map(fn (float $amount, string $bucket): array => [
                    'label' => mb_strtoupper($bucket === 'current' ? 'not yet due' : $bucket.' days'),
                    'value' => $amount,
                    'accent' => $bucket === '90+',
                ])
                ->values()
                ->all(),
            'footer' => [
                'Total outstanding — '.$invoices->count().' invoices',
                '',
                '',
                number_format((float) $report['total'], 0),
            ],
            'note' => mb_strtoupper($invoices->count().' invoices outstanding'),
            'balanced' => true,
        ];
    }

    /**
     * The account codes a statement line can be opened into.
     *
     * Drilling from a figure to the transactions behind it is the question every statement provokes, and
     * the register already answers it — but the register only offers postable asset accounts, so a line
     * that is not one of those must not look clickable. Offering the drill and then landing on a different
     * account's register would be worse than not offering it.
     *
     * Resolved once per pane rather than per row: this is a query, and a statement has fifty lines.
     *
     * @return array<int, string>
     */
    public function drillable(): array
    {
        return app(RegisterEntryService::class)->registerAccounts()->pluck('code')->all();
    }

    // -------------------------------------------------------- the other eight reports

    /**
     * One table, described the same way whatever the report.
     *
     * `grid` is CSS rather than a column count because the columns are not interchangeable: an account
     * name wants the slack and a figure wants a fixed width, and each report knows which of its own are
     * which. `numeric` says which to right-align — a column of amounts read down the left edge is a
     * column nobody can add up.
     *
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, array<string, mixed>>  $tiles
     * @return array<string, mixed>
     */
    private function table(
        string $key,
        string $title,
        string $subtitle,
        array $columns,
        string $grid,
        array $numeric,
        array $rows,
        array $tiles,
        string $note,
        ?array $footer = null,
        string $empty = 'Nothing to show for this period.',
    ): array {
        return [
            'kind' => 'table',
            'key' => $key,
            'title' => $title,
            'subtitle' => $subtitle,
            'columns' => $columns,
            'grid' => $grid,
            'numeric' => $numeric,
            'rows' => $rows,
            'tiles' => $tiles,
            'note' => $note,
            // A row across the bottom, one cell per column, so a figure sits under the column it totals
            // rather than in a single "total" cell that lines up with nothing.
            'footer' => $footer,
            'footer_span' => $footer === null ? 1 : self::span($footer),
            'empty' => $empty,
            'balanced' => true,
        ];
    }

    /**
     * How many columns the footer's label runs across: itself plus the blank cells after it.
     *
     * A register's figures start in the fourth column, so its label has three columns of room and needs
     * them — the first of those is 7rem wide, which fits "Closing" and not the rest of the sentence. Worked
     * out from the footer rather than declared per report so a report cannot state one and mean the other.
     *
     * @param  array<int, string>  $footer
     */
    private static function span(array $footer): int
    {
        $span = 1;

        while ($span < count($footer) && $footer[$span] === '') {
            $span++;
        }

        return $span;
    }

    /** Tax withheld per employee for the year, with what it was withheld on. */
    private function taxSummary(string $asOf, ?string $month = null): array
    {
        $report = app(WithholdingTaxSummary::class)->summary($this->fiscalYear($asOf)?->getKey(), $month);
        $employees = collect($report['employees'] ?? []);

        return $this->table(
            'TaxSummary',
            'Tax Summary',
            $this->subtitle('fiscal year '.($report['fiscal_year'] ?? '—').($month ? ' · '.$month : ' · the whole year')),
            ['Employee', 'Taxable', 'Tax withheld'],
            'minmax(0, 1fr) 10rem 10rem',
            [1, 2],
            $employees->map(fn (array $row): array => [
                (string) ($row['name'] ?? $row['employee'] ?? '—'),
                number_format((float) ($row['taxable'] ?? 0), 0),
                number_format((float) ($row['tax'] ?? 0), 0),
            ])->all(),
            [
                ['label' => 'TAXABLE TOTAL', 'value' => (float) ($report['taxable_total'] ?? 0), 'accent' => false],
                ['label' => 'TAX WITHHELD', 'value' => (float) ($report['tax_total'] ?? 0), 'accent' => true],
            ],
            mb_strtoupper($employees->count().' employees with tax withheld'),
            // Each figure under the column it totals, which is what a filing is checked against.
            [
                'Total — '.$employees->count().' employees',
                number_format((float) ($report['taxable_total'] ?? 0), 0),
                number_format((float) ($report['tax_total'] ?? 0), 0),
            ],
            $month
                ? "No tax was withheld in {$month}."
                : 'No tax has been withheld in this year yet.',
        );
    }

    /** What each contractor has been paid over the year. */
    private function contractorPayments(string $asOf): array
    {
        $report = app(ContractorPaymentSummary::class)->summary($this->fiscalYear($asOf)?->getKey());
        $contractors = collect($report['contractors'] ?? []);

        return $this->table(
            'ContractorPayments',
            'Contractor Payments',
            $this->subtitle('fiscal year '.($report['fiscal_year'] ?? '—')),
            ['Contractor', 'Paid'],
            'minmax(0, 1fr) 12rem',
            [1],
            $contractors->map(fn (array $row): array => [
                (string) ($row['name'] ?? '—'),
                number_format((float) ($row['paid'] ?? 0), 0),
            ])->all(),
            [['label' => 'TOTAL PAID', 'value' => (float) ($report['total'] ?? 0), 'accent' => true]],
            mb_strtoupper($contractors->count().' contractors paid'),
            ['Total — '.$contractors->count().' contractors', number_format((float) ($report['total'] ?? 0), 0)],
            'No contractor has been paid in this year yet.',
        );
    }

    /**
     * Planned against spent, by account.
     *
     * Needs a budget rather than a date — a fiscal year can hold more than one — so the pane asks, and
     * falls back to the most recent when it has not been asked yet.
     */
    private function budgetVsActual(string $asOf, int|string|null $budgetId): array
    {
        $budget = $budgetId ? Budget::find($budgetId) : Budget::query()->orderByDesc('id')->first();

        if ($budget === null) {
            return $this->table(
                'BudgetVsActual', 'Budget vs Actual', $this->subtitle('no budget'),
                ['Account', 'Planned', 'Actual', 'Variance'],
                'minmax(0, 1fr) 8rem 8rem 8rem', [1, 2, 3], [], [],
                'NO BUDGET HAS BEEN SET UP', null,
                'There is no budget to compare against yet.',
            );
        }

        $report = app(BudgetReportService::class)->report($budget);
        $rows = collect($report['rows'] ?? []);

        return $this->table(
            'BudgetVsActual',
            'Budget vs Actual',
            $this->subtitle(($budget->name ?? 'Budget').' · '.($report['from'] ?? '').' to '.($report['to'] ?? '')),
            ['Account', 'Planned', 'Actual', 'Variance'],
            'minmax(0, 1fr) 8rem 8rem 8rem',
            [1, 2, 3],
            $rows->map(fn (array $row): array => [
                trim(($row['code'] ?? '').' '.($row['name'] ?? '')),
                number_format((float) ($row['planned'] ?? 0), 0),
                number_format((float) ($row['actual'] ?? 0), 0),
                number_format((float) ($row['variance'] ?? 0), 0),
            ])->all(),
            [
                ['label' => 'PLANNED', 'value' => (float) ($report['net_planned'] ?? 0), 'accent' => false],
                ['label' => 'ACTUAL', 'value' => (float) ($report['net_actual'] ?? 0), 'accent' => true],
            ],
            mb_strtoupper($rows->count().' accounts in this budget'),
            [
                'Net — '.$rows->count().' accounts',
                number_format((float) ($report['net_planned'] ?? 0), 0),
                number_format((float) ($report['net_actual'] ?? 0), 0),
                number_format((float) (($report['net_actual'] ?? 0) - ($report['net_planned'] ?? 0)), 0),
            ],
        );
    }

    /** What FBR has not accepted, and what it never received. */
    private function fbrReconciliation(string $asOf): array
    {
        $reconciliation = app(FbrReconciliation::class);

        if (! $reconciliation->enabled()) {
            return $this->table(
                'FbrInvoiceReporting', 'FBR Invoice Reporting', $this->subtitle('integration off'),
                ['Finding', 'Invoice', 'Detail'], 'minmax(0, 14rem) 10rem minmax(0, 1fr)', [], [], [],
                'THE FBR INTEGRATION IS NOT SWITCHED ON', null,
                'The FBR integration is not switched on for this company, so there is nothing to reconcile.',
            );
        }

        $findings = collect($reconciliation->findings());

        return $this->table(
            'FbrInvoiceReporting',
            'FBR Invoice Reporting',
            $this->subtitle('as of '.Carbon::parse($asOf)->format('j M Y')),
            ['Finding', 'Invoice', 'Detail'],
            'minmax(0, 14rem) 10rem minmax(0, 1fr)',
            [],
            $findings->map(function ($finding): array {
                // findings() is the reconciliation's own vocabulary and has changed shape before, so each
                // row is read defensively rather than destructured — a wrong key here would be a blank
                // column on a compliance report.
                if (! is_array($finding)) {
                    return ['—', '—', (string) $finding];
                }

                return [
                    (string) ($finding['kind'] ?? $finding['finding'] ?? '—'),
                    (string) ($finding['invoice_number'] ?? $finding['invoice'] ?? '—'),
                    (string) ($finding['detail'] ?? $finding['reason'] ?? ''),
                ];
            })->all(),
            [['label' => 'FINDINGS', 'value' => (float) $reconciliation->total(), 'accent' => $reconciliation->total() > 0]],
            $reconciliation->total() > 0
                ? mb_strtoupper($reconciliation->total().' invoices need attention')
                : 'EVERY INVOICE IS ACCOUNTED FOR',
            null,
            'Every issued invoice has been accepted, and FBR has nothing this company has not sent.',
        );
    }

    /** The cash float for the month the date falls in: what was spent, and what is left. */
    private function pettyCash(string $asOf, ?string $month = null): array
    {
        // The month filter names a month of the fiscal year; without one the date's own month is used.
        $date = $month
            ? Carbon::parse($month.' '.Carbon::parse($asOf)->year)
            : Carbon::parse($asOf);

        // The float lives in one nominated account, and a company whose chart does not have it has no
        // petty cash book. The service says so by throwing, which is right for a caller that needs the
        // account and wrong for this one: on a screen that draws every report in turn, one company's
        // missing account would take the whole explorer down with it — including the sixteen reports
        // that have nothing to do with petty cash. Said rather than thrown, like the missing budget and
        // the switched-off integration above.
        try {
            $summary = app(PettyCashService::class)->monthSummary($date);
        } catch (\Throwable $exception) {
            return $this->table(
                'PettyCashBook', 'Petty Cash Book', $this->subtitle('no float account'),
                ['Voucher', 'Date', 'Details', 'Paid'],
                '7rem 7rem minmax(0, 1fr) 8rem', [3], [], [],
                'THE PETTY CASH ACCOUNT IS NOT SET UP', null,
                'This company\'s chart of accounts has no petty cash account ('
                .PettyCashService::ACCOUNT_CODE.'), so there is no float to report on.',
            );
        }
        $paid = collect($summary['paid'] ?? []);

        return $this->table(
            'PettyCashBook',
            'Petty Cash Book',
            $this->subtitle($summary['month'] ?? Carbon::parse($asOf)->format('F Y')),
            ['Voucher', 'Date', 'Details', 'Paid'],
            '7rem 7rem minmax(0, 1fr) 8rem',
            [3],
            $paid->map(fn (array $row): array => [
                (string) ($row['voucher_no'] ?? '—'),
                (string) ($row['date'] ?? ''),
                (string) ($row['details'] ?? ''),
                number_format((float) ($row['amount'] ?? 0), 0),
            ])->all(),
            [
                ['label' => 'OPENING', 'value' => (float) ($summary['opening_balance'] ?? 0), 'accent' => false],
                ['label' => 'PAID OUT', 'value' => (float) ($summary['paid_total'] ?? 0), 'accent' => false],
                ['label' => 'CLOSING', 'value' => (float) ($summary['closing_balance'] ?? 0), 'accent' => true],
            ],
            ($summary['replenished'] ?? false) ? 'THE FLOAT HAS BEEN REPLENISHED THIS MONTH' : 'THE FLOAT HAS NOT BEEN REPLENISHED THIS MONTH',
            ['Paid out — '.$paid->count().' vouchers', '', '', number_format((float) ($summary['paid_total'] ?? 0), 0)],
            'Nothing was paid out of the float this month.',
        );
    }

    /** Foreign balances at the rate on a date, and the difference that would be posted. */
    private function revaluation(string $asOf): array
    {
        $preview = app(CurrencyRevaluationService::class)->preview($asOf);
        $rows = collect($preview['rows'] ?? []);

        return $this->table(
            'CurrencyRevaluation',
            'Currency Revaluation',
            $this->subtitle('as of '.Carbon::parse($preview['as_of'] ?? $asOf)->format('j M Y')),
            ['Account', 'Currency', 'Foreign', 'Rate', 'Translated', 'Adjustment'],
            'minmax(0, 1fr) 6rem 8rem 6rem 9rem 9rem',
            [2, 3, 4, 5],
            $rows->map(fn (array $row): array => [
                trim(($row['code'] ?? '').' '.($row['name'] ?? '')),
                (string) ($row['currency_code'] ?? ''),
                number_format((float) ($row['foreign_balance'] ?? 0), 0),
                (string) ($row['rate'] ?? ''),
                number_format((float) ($row['translated'] ?? 0), 0),
                number_format((float) ($row['adjustment'] ?? 0), 0),
            ])->all(),
            [['label' => 'NET ADJUSTMENT', 'value' => (float) ($preview['net'] ?? 0), 'accent' => true]],
            filled($preview['problems'] ?? [])
                ? mb_strtoupper(count($preview['problems']).' accounts have no rate for this date')
                : mb_strtoupper($rows->count().' foreign balances'),
            [
                'Net adjustment — '.$rows->count().' balances',
                '', '', '', '',
                number_format((float) ($preview['net'] ?? 0), 0),
            ],
            'No account holds a foreign balance at this date.',
        );
    }

    /** One account, every transaction against it, running balance. */
    private function accountRegister(string $asOf, int|string|null $accountId): array
    {
        $accounts = app(RegisterEntryService::class)->registerAccounts();
        $account = $accountId ? $accounts->firstWhere('id', (int) $accountId) : $accounts->first();

        if ($account === null) {
            return $this->table(
                'AccountRegister', 'Account Register', $this->subtitle('no register account'),
                ['Date', 'Ref', 'Description', 'Debit', 'Credit', 'Balance'],
                '7rem 5rem minmax(0, 1fr) 8rem 8rem 9rem', [3, 4, 5], [], [],
                'NO ACCOUNT CAN BE REGISTERED YET', null,
                'No postable asset account exists to register against.',
            );
        }

        // The financial year to date, so the register reads as a period rather than as everything ever
        // posted — and as the *same* period the statements beside it use. See ReportPeriod.
        $from = ReportPeriod::toDate($asOf)['from'];
        $register = app(RegisterEntryService::class)->registerRows($account, $from, $asOf);
        $rows = collect($register['rows'] ?? []);

        return $this->table(
            'AccountRegister',
            'Account Register',
            $this->subtitle($account->code.' '.$account->name.' · to '.Carbon::parse($asOf)->format('j M Y')),
            ['Date', 'Ref', 'Description', 'Debit', 'Credit', 'Balance'],
            '7rem 5rem minmax(0, 1fr) 8rem 8rem 9rem',
            [3, 4, 5],
            $rows->map(fn (array $row): array => [
                (string) ($row['date'] ?? ''),
                (string) ($row['num'] ?? ''),
                (string) ($row['description'] ?? ''),
                $row['debit'] ? number_format((float) $row['debit'], 0) : '',
                $row['credit'] ? number_format((float) $row['credit'], 0) : '',
                number_format((float) ($row['balance'] ?? 0), 0),
            ])->all(),
            [
                ['label' => 'OPENING', 'value' => (float) ($register['opening_balance'] ?? 0), 'accent' => false],
                ['label' => 'CLOSING', 'value' => (float) ($register['closing_balance'] ?? 0), 'accent' => true],
            ],
            mb_strtoupper($rows->count().' transactions in this period'),
            // The row a register is read for: what went in, what went out, what is left.
            [
                'Closing — '.$rows->count().' transactions',
                '',
                '',
                // Summed from the register's own figures rather than from the formatted cells above:
                // parsing "1,250" back into a number to add it up is a bug waiting for a thousands
                // separator to change.
                number_format((float) collect($register['rows'] ?? [])->sum(fn (array $row): float => (float) ($row['debit'] ?? 0)), 0),
                number_format((float) collect($register['rows'] ?? [])->sum(fn (array $row): float => (float) ($row['credit'] ?? 0)), 0),
                number_format((float) ($register['closing_balance'] ?? 0), 0),
            ],
            'Nothing has been posted to this account in this period.',
        );
    }

    /** The ledger, searched. */
    private function findTransactions(string $asOf, string $search): array
    {
        $lines = JournalEntryLine::query()
            ->with(['account', 'journalEntry'])
            ->whereHas('journalEntry', fn ($query) => $query
                ->where('status', JournalEntry::STATUS_POSTED)
                ->where('entry_date', '<=', $asOf))
            ->when(filled($search), fn ($query) => $query->where(fn ($inner) => $inner
                ->where('description', 'like', "%{$search}%")
                ->orWhereHas('journalEntry', fn ($entry) => $entry->where('memo', 'like', "%{$search}%"))
                ->orWhereHas('account', fn ($account) => $account
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%"))))
            ->latest('id')
            ->limit(200)
            ->get();

        return $this->table(
            'FindTransactions',
            'Find Transactions',
            $this->subtitle(filled($search) ? "matching “{$search}”" : 'the latest posted lines'),
            ['Date', 'Account', 'Description', 'Debit', 'Credit'],
            '7rem minmax(0, 14rem) minmax(0, 1fr) 8rem 8rem',
            [3, 4],
            $lines->map(fn (JournalEntryLine $line): array => [
                (string) ($line->journalEntry?->entry_date?->format('Y-m-d') ?? ''),
                trim(($line->account?->code ?? '').' '.($line->account?->name ?? '')),
                (string) ($line->description ?: $line->journalEntry?->memo),
                $line->debit_amount ? number_format((float) $line->debit_amount, 0) : '',
                $line->credit_amount ? number_format((float) $line->credit_amount, 0) : '',
            ])->all(),
            [['label' => 'LINES SHOWN', 'value' => (float) $lines->count(), 'accent' => false]],
            // Bounded, and this one stays bounded on purpose: every other report here is limited by a
            // period or by what is outstanding, but an unfiltered ledger search is limited by nothing —
            // a mature book would try to render every line ever posted. The bound is stated rather than
            // silent, which is the part that matters.
            $lines->count() === 200
                ? 'SHOWING THE 200 MOST RECENT MATCHES — SEARCH TO NARROW THEM'
                : mb_strtoupper($lines->count().' matching lines'),
            // Of the lines shown, which is what the label says: with a bound in play a "total" that claimed
            // to be the total of everything matching would be false.
            [
                'Shown — '.$lines->count().' lines',
                '', '',
                number_format((float) $lines->sum(fn (JournalEntryLine $line): float => (float) $line->debit_amount), 0),
                number_format((float) $lines->sum(fn (JournalEntryLine $line): float => (float) $line->credit_amount), 0),
            ],
            filled($search) ? 'Nothing in the ledger matches that.' : 'Nothing has been posted yet.',
        );
    }

    /** The fiscal year a date falls in, or the current one. */
    private function fiscalYear(string $asOf): ?FiscalYear
    {
        return ReportPeriod::yearFor($asOf);
    }

    // ------------------------------------------------------------------ file reports

    /**
     * What a file report would contain, without producing the file.
     *
     * A bank file is not a page: it is a release, with a confirmation and a batch reference, and pressing
     * that button from a preview pane would be the wrong place to do it. So the pane says how many rows,
     * for what period, totalling what — which is exactly what somebody wants to know *before* going to
     * the screen that releases it — and links there.
     *
     * @return array<string, mixed>
     */
    private function file(string $key, string $asOf): array
    {
        $date = Carbon::parse($asOf);
        $year = FiscalYear::query()
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())
            ->first() ?? FiscalYear::current();

        [$title, $rows, $total, $unit] = match ($key) {
            'SalaryBankFile' => $this->salaryFileSummary($date, $year),
            'FbrTaxFile' => $this->taxFileSummary($year),
            default => $this->paymentFileSummary($year),
        };

        return [
            'kind' => 'file',
            'key' => $key,
            'title' => $title,
            'subtitle' => $this->subtitle($year?->name ? 'fiscal year '.$year->name : 'as of '.$date->format('j M Y')),
            'tiles' => [
                ['label' => mb_strtoupper($unit), 'value' => (float) $rows, 'accent' => false],
                ['label' => 'TOTAL VALUE', 'value' => $total, 'accent' => true],
            ],
            'note' => $rows > 0
                ? mb_strtoupper('the file would carry '.$rows.' '.mb_strtolower($unit))
                : 'NOTHING TO SEND FOR THIS PERIOD',
            'rows_count' => $rows,
            'balanced' => true,
        ];
    }

    /** @return array{0: string, 1: int, 2: float, 3: string} */
    private function salaryFileSummary(Carbon $date, ?FiscalYear $year): array
    {
        $rows = $year === null ? [] : app(SalaryBankExportService::class)
            ->paymentsForMonth($date->format('F'), $year);

        return ['Salary Bank File', count($rows), (float) collect($rows)->sum('amount'), 'Payments'];
    }

    /** @return array{0: string, 1: int, 2: float, 3: string} */
    private function taxFileSummary(?FiscalYear $year): array
    {
        $payslips = Payslip::query()
            ->when($year !== null, fn ($query) => $query->where('fiscal_year_id', $year->id))
            ->where('withholding_tax', '>', 0);

        return ['FBR Tax File', (clone $payslips)->count(), (float) $payslips->sum('withholding_tax'), 'Payslips'];
    }

    /** @return array{0: string, 1: int, 2: float, 3: string} */
    private function paymentFileSummary(?FiscalYear $year): array
    {
        $payments = \App\Modules\Accounting\Models\Payment::query()
            ->whereNull('batch_reference')
            ->when($year !== null, fn ($query) => $query->whereBetween('value_date', [$year->start_date, $year->end_date]));

        return ['Bank Payment File', (clone $payments)->count(), (float) $payments->sum('amount'), 'Payments'];
    }

    private function subtitle(string $period): string
    {
        return trim((Company::current()?->name ?? '').' · '.$period, ' ·');
    }
}
