<?php

namespace App\Modules\Accounting\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Accounting\Services\FinancialReportService;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Revenue against expenses, month by month — `docs/reports-expansion-plan.md` Phase 5.5.
 *
 * **Fed by `FinancialReportService::profitAndLoss()`, which is the service behind the Profit & Loss report.**
 * Phase 5's own rule, and the reason it is stated: "a widget and a report that disagree about a number is
 * worse than either alone, because the person who spots it cannot tell which to believe". Re-deriving income
 * and expense totals from journal lines here would have been quicker and would have been that second query.
 *
 * **The dashboard's period chooses where the series *ends*, not how long it is.** The plan asks for twelve
 * months, and twelve months is the point: a chart is a shape, and honouring a one-month period by drawing one
 * bar would destroy the widget rather than filter it. So the series always runs twelve months and the last of
 * them is the month the period ends in — which is a real use of the filter, because reading the dashboard as
 * at last June gives the twelve months to last June.
 *
 * **Twelve calls to `profitAndLoss()` is twelve months of aggregates**, and this is the widget Phase 5.8's
 * cache exists for. It is `$isLazy` so the dashboard renders without waiting for it, and until 5.8 lands it
 * is the most expensive thing on the page — stated here rather than discovered.
 */
class RevenueAndExpensesChart extends ChartWidget
{
    use WidgetBelongsToModule;

    /**
     * The dashboard's period, handed over by `Dashboard::getWidgetData()`.
     *
     * Only the end is used, for the reason above. `$periodFrom` is declared because the page passes it and a
     * widget that did not accept it would take the page down with an unknown-property error the moment
     * somebody added a filter.
     */
    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * Money first — Phase 5.7 asks for money → sales → service → people rather than discovery order.
     *
     * Banded ten apart so a group can gain a widget without renumbering its neighbours: money 10–19, sales
     * 20–29, service 30–39, people 40–49, inventory 50–59. The nine widgets that predate this phase still sit
     * on 0–8 and therefore above; placing them in the bands is Phase 5.7's own job.
     */
    protected static ?int $sort = 10;

    protected static bool $isLazy = true;

    public function getHeading(): ?string
    {
        return 'Revenue and expenses';
    }

    public function getDescription(): ?string
    {
        $months = $this->months();

        return sprintf(
            'Twelve months to %s',
            Carbon::parse(end($months))->format('F Y'),
        );
    }

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        // The same permission the Profit & Loss report is gated on. A chart of the same figures behind a
        // laxer gate would be a way to read a report somebody may not open.
        return (bool) auth()->user()?->can('ReportView');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $reports = app(FinancialReportService::class);
        $revenue = [];
        $expenses = [];
        $labels = [];

        foreach ($this->months() as $month) {
            $start = Carbon::parse($month);
            // Capped at the period's end, so the last column is the month *so far* rather than a whole month
            // padded with a future nobody has traded in yet.
            $end = $start->copy()->endOfMonth()->min(Carbon::parse($this->endsOn()));

            $statement = $reports->profitAndLoss($start->toDateString(), $end->toDateString());

            $revenue[] = round((float) ($statement['income']['total'] ?? 0), 2);
            $expenses[] = round((float) ($statement['expenses']['total'] ?? 0), 2);
            $labels[] = $start->format('M y');
        }

        return [
            'datasets' => [
                ['label' => 'Revenue', 'data' => $revenue],
                ['label' => 'Expenses', 'data' => $expenses],
            ],
            'labels' => $labels,
        ];
    }

    /**
     * The twelve month-starts the series covers, oldest first.
     *
     * @return array<int, string>
     */
    private function months(): array
    {
        $end = Carbon::parse($this->endsOn())->startOfMonth();

        return array_map(
            fn (int $back): string => $end->copy()->subMonthsNoOverflow(11 - $back)->toDateString(),
            range(0, 11),
        );
    }

    /**
     * The date the series ends on.
     *
     * Falls back to today, because a widget rendered outside the dashboard — the smoke test does exactly
     * that — has no period handed to it, and a null reaching `Carbon::parse()` would silently become today
     * anyway. Saying so is better than relying on it.
     */
    private function endsOn(): string
    {
        return $this->periodTo ?? now()->toDateString();
    }
}
