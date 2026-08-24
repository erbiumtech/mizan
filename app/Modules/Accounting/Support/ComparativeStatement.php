<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Core\Models\Company;
use App\Support\Reporting\ReportComparison;
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
    public function for(string $key, string $asOf, string $basis = ReportComparison::PREVIOUS_YEAR): ?array
    {
        return match ($key) {
            'BalanceSheet' => $this->balanceSheet($asOf, $basis),
            // The period comes from the basis rather than being fixed at the financial year to date — Phase
            // 4.2. Year and none still give the year to date, which is what this has always shown; month and
            // quarter narrow it, because a comparison shorter than the period compared is not a comparison.
            // See ReportComparison.
            'ProfitAndLoss' => $this->profitAndLoss($asOf, $basis),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    /**
     * The balance sheet, and the same balance at an earlier date.
     *
     * An as-at rather than a period, so the basis only has to answer one question — which earlier date —
     * and every basis has a sensible answer to it. This is the statement where a month-on-month comparison
     * is unproblematic: the current figure is the balance today either way.
     */
    public function balanceSheet(string $asOf, string $basis = ReportComparison::PREVIOUS_YEAR): array
    {
        $current = $this->reports->balanceSheet($asOf);
        $previousDate = ReportComparison::shift($basis, $asOf);
        $previous = $previousDate === null ? null : $this->reports->balanceSheet($previousDate);

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
            'previous_label' => $previousDate === null ? null : $this->date($previousDate),
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

    /**
     * The profit and loss over the basis's period, and the same length of period before it.
     *
     * **Takes the as-at date and the basis rather than a range**, which is the change Phase 4.2 needed: the
     * caller used to pass the financial year to date and a boolean, and a month or quarter basis has to
     * narrow the current period as well as shift the comparison. A range passed in from outside could not be
     * narrowed without the caller knowing the rule, and then two places would know it.
     *
     * @return array<string, mixed>
     */
    public function profitAndLoss(string $asOf, string $basis = ReportComparison::PREVIOUS_YEAR): array
    {
        ['from' => $from, 'to' => $to] = ReportComparison::currentRange($basis, $asOf);
        $current = $this->reports->profitAndLoss($from, $to);

        $prior = ReportComparison::previousRange($basis, $asOf);
        $previous = $prior === null ? null : $this->reports->profitAndLoss($prior['from'], $prior['to']);

        return [
            'key' => 'ProfitAndLoss',
            'title' => 'Profit & Loss',
            'subtitle' => $this->subtitle($this->date($from).' to '.$this->date($to).' · accrual basis'),
            'current_label' => $this->date($to),
            'previous_label' => $prior === null ? null : $this->date($prior['to']),
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
