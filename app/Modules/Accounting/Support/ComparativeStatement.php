<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Core\Models\Company;
use Carbon\Carbon;

/**
 * A statement and the same statement a year earlier, side by side.
 *
 * The reports explorer shows a whole statement — sections, subtotals, totals — with a comparison
 * column. Nothing in FinancialReportService knew about comparison, and nothing needed to: every
 * statement there is already parameterised by date, so the prior year is the same call with the dates
 * moved back twelve months. This joins the two results; it computes no accounting of its own, which is
 * the point. A comparison figure derived here rather than by the ledger would be a second opinion about
 * what the books say.
 *
 * **Two statements, not all of them.** The balance sheet and the profit and loss share a shape — named
 * sections of accounts, one amount each, a total per section — which is what this table renders. The
 * trial balance carries a debit *and* a credit per row, and the cash flow's operating section is built
 * from net income and working-capital movements rather than from accounts. Both would need their own
 * normalisation, and inventing a single amount for them here would misrepresent them. `supports()` says
 * which are ready, and the explorer offers the rest as a link to their own page.
 */
class ComparativeStatement
{
    /**
     * The catalogue keys this can render, in the Reports hub's own terms
     * (App\Modules\Core\Filament\Pages\Reports::catalogue()).
     */
    public const SUPPORTED = ['BalanceSheet', 'ProfitAndLoss'];

    public function __construct(private FinancialReportService $reports) {}

    public static function supports(?string $key): bool
    {
        return in_array($key, self::SUPPORTED, true);
    }

    /**
     * @return array<string, mixed>|null null when the report is one this cannot render
     */
    public function for(string $key, string $asOf, bool $comparison = true): ?array
    {
        return match ($key) {
            'BalanceSheet' => $this->balanceSheet($asOf, $comparison),
            // The period runs from the start of the financial year the date falls in, which is what
            // makes a P&L comparable to the same months a year earlier rather than to a different span.
            'ProfitAndLoss' => $this->profitAndLoss(
                Carbon::parse($asOf)->startOfYear()->toDateString(),
                $asOf,
                $comparison,
            ),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function balanceSheet(string $asOf, bool $comparison = true): array
    {
        $current = $this->reports->balanceSheet($asOf);
        $previousDate = Carbon::parse($asOf)->subYear()->toDateString();
        $previous = $comparison ? $this->reports->balanceSheet($previousDate) : null;

        $sections = [
            $this->section('ASSETS', 'Total assets', $current['assets'], $previous['assets'] ?? null),
            $this->section('LIABILITIES', 'Total liabilities', $current['liabilities'], $previous['liabilities'] ?? null),
            $this->section(
                'EQUITY',
                'Total equity',
                $this->equityWithEarnings($current),
                $previous === null ? null : $this->equityWithEarnings($previous),
            ),
        ];

        return [
            'key' => 'BalanceSheet',
            'title' => 'Balance Sheet',
            'subtitle' => $this->subtitle('as of '.$this->date($asOf).' · accrual basis'),
            'current_label' => $this->date($asOf),
            'previous_label' => $comparison ? $this->date($previousDate) : null,
            'sections' => $sections,
            'tiles' => [
                ['label' => 'TOTAL ASSETS', 'value' => $current['assets']['total'], 'accent' => false],
                ['label' => 'TOTAL LIABILITIES', 'value' => $current['liabilities']['total'], 'accent' => false],
                ['label' => 'TOTAL EQUITY', 'value' => $current['equity_total'], 'accent' => true],
            ],
            // The identity, stated rather than assumed. `balanced` comes from the ledger.
            'note' => $current['balanced']
                ? 'BALANCED · ASSETS = LIABILITIES + EQUITY'
                : 'OUT OF BALANCE BY '.$this->money(round($current['assets']['total'] - $current['liabilities_and_equity_total'], 2)),
            'balanced' => $current['balanced'],
            'closing' => [
                'label' => 'Total liabilities and equity',
                'current' => $current['liabilities_and_equity_total'],
                'previous' => $previous['liabilities_and_equity_total'] ?? null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function profitAndLoss(string $from, string $to, bool $comparison = true): array
    {
        $current = $this->reports->profitAndLoss($from, $to);

        $previousFrom = Carbon::parse($from)->subYear()->toDateString();
        $previousTo = Carbon::parse($to)->subYear()->toDateString();
        $previous = $comparison ? $this->reports->profitAndLoss($previousFrom, $previousTo) : null;

        return [
            'key' => 'ProfitAndLoss',
            'title' => 'Profit & Loss',
            'subtitle' => $this->subtitle($this->date($from).' to '.$this->date($to).' · accrual basis'),
            'current_label' => $this->date($to),
            'previous_label' => $comparison ? $this->date($previousTo) : null,
            'sections' => [
                $this->section('INCOME', 'Total income', $current['income'], $previous['income'] ?? null),
                $this->section('EXPENSES', 'Total expenses', $current['expenses'], $previous['expenses'] ?? null),
            ],
            'tiles' => [
                ['label' => 'TOTAL INCOME', 'value' => $current['income']['total'], 'accent' => false],
                ['label' => 'TOTAL EXPENSES', 'value' => $current['expenses']['total'], 'accent' => false],
                ['label' => $current['is_profit'] ? 'NET PROFIT' : 'NET LOSS', 'value' => $current['net_profit'], 'accent' => true],
            ],
            'note' => $current['is_profit'] ? 'IN PROFIT FOR THE PERIOD' : 'AT A LOSS FOR THE PERIOD',
            'balanced' => true,
            'closing' => [
                'label' => $current['is_profit'] ? 'Net profit' : 'Net loss',
                'current' => $current['net_profit'],
                'previous' => $previous['net_profit'] ?? null,
            ],
        ];
    }

    /**
     * Equity with the period's own result added as a line.
     *
     * It is a computed figure rather than an account, and it belongs here: without it the section does
     * not add up to what funds the assets, and the statement would render as out of balance while the
     * ledger was perfectly fine.
     *
     * Left out when it is nothing, which is the same rule positionSection() applies to accounts — a
     * statement listing "Retained earnings for the period 0" against a company that has not traded yet
     * is a line that says nothing. The section total still comes from the ledger either way.
     *
     * @param  array<string, mixed>  $sheet
     * @return array{rows: array<int, array{code: string, name: string, amount: float}>, total: float}
     */
    private function equityWithEarnings(array $sheet): array
    {
        $rows = $sheet['equity']['rows'];
        $earnings = (float) $sheet['retained_earnings_for_period'];

        if (abs($earnings) >= 0.005) {
            // Sorted last within equity by a code that cannot collide with a real one — the result of
            // the period reads after the capital that was put in, which is the order a statement uses.
            $rows[] = ['code' => 'zzzz', 'name' => 'Retained earnings for the period', 'amount' => $earnings];
        }

        return ['rows' => $rows, 'total' => (float) $sheet['equity_total']];
    }

    /**
     * One section, with both periods' rows joined by account code.
     *
     * A union rather than an intersection, and ordered by code so the two periods read down the page in
     * the same order as the chart of accounts. An account opened this year has no prior figure and an
     * account closed last year has no current one; both appear, with the missing side blank rather than
     * zero — a blank says "not there", a nought says "there, and empty", and in a statement those are
     * different claims.
     *
     * @param  array{rows: array<int, array{code: string, name: string, amount: float}>, total: float}  $current
     * @param  array{rows: array<int, array{code: string, name: string, amount: float}>, total: float}|null  $previous
     * @return array<string, mixed>
     */
    private function section(string $label, string $totalLabel, array $current, ?array $previous): array
    {
        $rows = [];

        foreach ($current['rows'] as $row) {
            $rows[$row['code']] = [
                'code' => $row['code'],
                'label' => $row['name'],
                'current' => (float) $row['amount'],
                'previous' => null,
            ];
        }

        foreach ($previous['rows'] ?? [] as $row) {
            $rows[$row['code']] ??= [
                'code' => $row['code'],
                'label' => $row['name'],
                'current' => null,
                'previous' => null,
            ];

            $rows[$row['code']]['previous'] = (float) $row['amount'];
        }

        ksort($rows);

        return [
            'label' => $label,
            'rows' => array_values(array_map(
                fn (array $row): array => [...$row, 'change' => $this->change($row['current'], $row['previous'])],
                $rows,
            )),
            'total' => [
                'label' => $totalLabel,
                'current' => (float) $current['total'],
                'previous' => $previous === null ? null : (float) $previous['total'],
                'change' => $this->change((float) $current['total'], $previous === null ? null : (float) $previous['total']),
            ],
        ];
    }

    /**
     * The percentage move, or null where there is nothing to move from.
     *
     * Null rather than a large number when the prior figure is zero: "+1,200%" against a rounding
     * remnant reads as a finding when it is an artefact, and a first year of trading would show one on
     * every line. The view renders these as a dash.
     */
    private function change(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null || abs($previous) < 0.005) {
            return null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    private function subtitle(string $period): string
    {
        return trim((Company::current()?->name ?? '').' · '.$period, ' ·');
    }

    private function date(string $date): string
    {
        return Carbon::parse($date)->format('j M Y');
    }

    private function money(float $amount): string
    {
        return number_format($amount, 0);
    }
}
