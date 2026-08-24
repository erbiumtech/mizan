<?php

namespace App\Modules\Crm\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Crm\Services\PipelineReports;
use App\Support\Reporting\ReportFigures;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The weighted forecast, against what was targeted — `docs/reports-expansion-plan.md` Phase 5.3.
 *
 * **Both figures come from `PipelineReports`** — `forecast()` and `attainment()`, the services behind the
 * Sales Forecast and Target Attainment reports. Putting them in one widget is the item's own instruction
 * ("weighted forecast against target attainment") and it is worth having: a forecast without a target is a
 * number, and a target without a forecast is a hope.
 *
 * **The forecast reads the dashboard's period as a window**, because that is what a forecast is: deals by
 * expected close date between two dates. This is the widget the period filter matters most to — "this month"
 * and "financial year to date" are genuinely different questions about the same pipeline.
 *
 * **Attainment is an as-at, and that asymmetry is deliberate.** `attainment()` takes one date and finds the
 * targets covering it, because a target is a period of its own — somebody's quarter — and asking "which
 * targets are live" is a question about a moment. Handing it a range would mean deciding which end, and
 * either choice would be arbitrary; the period's end is the one that answers "where are we now".
 *
 * **Attainment is reported as a count, not an average.** Averaging one salesperson at 200% with three at 40%
 * gives 80% and describes nobody. How many are on track out of how many have a target is the figure a sales
 * manager acts on.
 */
class ForecastAgainstTargetOverview extends StatsOverviewWidget
{
    use WidgetBelongsToModule;

    /** At or above this, a target is on track. Whole percent, because a target is not a measurement. */
    public const ON_TRACK_PCT = 100.0;

    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    /** Sales band — see RevenueAndExpensesChart for the scheme. */
    protected static ?int $sort = 21;

    protected static bool $isLazy = true;

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return (bool) auth()->user()?->can('ReportView');
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $reports = app(PipelineReports::class);

        $forecast = $reports->forecast($this->periodFrom, $this->periodTo);
        $targets = $reports->attainment($this->periodTo);

        $onTrack = count(array_filter(
            $targets,
            fn (array $row): bool => ($row['attainment_pct'] ?? null) !== null
                && $row['attainment_pct'] >= self::ON_TRACK_PCT,
        ));

        // Only targets with a goal worth measuring against. `attainment_pct` is null where the goal is
        // nought, and counting those in the denominator would report a company as behind on targets nobody
        // set a number for.
        $measurable = count(array_filter(
            $targets,
            fn (array $row): bool => ($row['attainment_pct'] ?? null) !== null,
        ));

        return [
            Stat::make('Weighted forecast', ReportFigures::money($forecast['weighted']))
                ->description(sprintf(
                    '%d open deals · %s unweighted',
                    $forecast['count'],
                    ReportFigures::money($forecast['plain']),
                ))
                ->color('primary'),

            // Named when the total crossed currencies, because it was converted at the rate each deal
            // recorded rather than at today's — the service reports the codes for exactly this reason.
            Stat::make('Currencies', count($forecast['currencies']) > 1
                ? implode(', ', $forecast['currencies'])
                : 'One')
                ->description(count($forecast['currencies']) > 1
                    ? 'converted at each deal\'s own rate'
                    : 'no conversion in this figure')
                ->color('gray'),

            Stat::make('Targets on track', $measurable === 0 ? '—' : $onTrack.' of '.$measurable)
                ->description($measurable === 0
                    ? 'nobody has a target with a number on it'
                    : 'as at '.($this->periodTo ?? now()->toDateString()))
                ->color(match (true) {
                    $measurable === 0 => 'gray',
                    $onTrack === $measurable => 'success',
                    $onTrack === 0 => 'danger',
                    default => 'warning',
                }),
        ];
    }
}
