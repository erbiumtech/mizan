<?php

namespace App\Modules\Timesheets\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Timesheets\Services\TimesheetService;
use App\Support\Reporting\DashboardCache;
use App\Support\Reporting\DashboardWidgets;
use App\Support\Reporting\ReportFigures;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * How much of the time booked was billable — `docs/reports-expansion-plan.md` Phase 5.4.
 *
 * The plan asks for "billable utilisation this month". **What this reports is billable *share*, and the
 * difference is deliberate** — `TimesheetService` refuses to state capacity and gives the reason in as many
 * words: `utilisationFor()` returns `expected_hours` as null in every branch because a rule making timesheets
 * and attendance reconcile "would make people book the difference somewhere to make the screen agree, which
 * produces worse data than the gap it closed". Inventing a denominator on a dashboard would undo that
 * decision quietly, in the place it would be read as fact. Billable share needs no assumption about what
 * somebody's month should have held.
 *
 * **Fed by `TimesheetService::utilisation()`, not `utilisationFor()`, and the plan names the latter.** That
 * one answers for a single employee and reaches `AttendanceCalendar::summarise()`, which walks every day of
 * the month — the service's own docblock calls looping it over a company "hundreds of queries for one
 * screen ... the exact fault `docs/page-load-performance-plan.md` was written about". `utilisation()` is the
 * company-wide figure the Timesheet Utilisation report uses, at three queries a month whatever the headcount.
 *
 * **Every month the period touches**, summed. `utilisation()` answers per month by design, so a quarter is
 * three calls and a financial year to date at most twelve — bounded, small, and honest about the span rather
 * than showing one month under a label that says otherwise.
 */
class BillableShareOverview extends StatsOverviewWidget
{
    use WidgetBelongsToModule;

    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    protected static ?int $sort = DashboardWidgets::SERVICE + 1;

    protected static bool $isLazy = true;

    /**
     * No polling — `docs/reports-expansion-plan.md` Phase 5.7 asks for it and Filament's default is against
     * it: `CanPoll::$pollingInterval` is `'5s'`, so every widget in this panel was re-running its aggregates
     * every five seconds, per open tab, unasked. On a dashboard of twenty-three widgets that is the cost
     * Phase 5.8's cache exists to avoid, incurred twelve times a minute instead of once a page.
     */
    protected ?string $pollingInterval = null;

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
        ['billable' => $billable, 'booked' => $booked, 'people' => $people] = DashboardCache::remember(
            'billable-share',
            ['from' => $this->periodFrom, 'to' => $this->periodTo],
            fn (): array => $this->totals(),
        );

        $share = $booked > 0 ? round($billable / $booked * 100, 1) : null;

        return [
            Stat::make('Billable share', $share === null ? '—' : $share.'%')
                ->description($booked > 0
                    ? ReportFigures::money($billable, 1).' of '.ReportFigures::money($booked, 1).' hours booked'
                    : 'nothing was booked in this period')
                ->color(match (true) {
                    $share === null => 'gray',
                    $share >= 70 => 'success',
                    $share >= 50 => 'warning',
                    default => 'danger',
                }),

            Stat::make('Hours booked', ReportFigures::money($booked, 1))
                // No capacity figure beside it, on purpose — see the class docblock. Headcount is what the
                // data supports: how many people recorded anything at all.
                ->description($people === 1 ? 'by one person' : 'by '.$people.' people')
                ->color('gray'),
        ];
    }

    /**
     * The hours, summed across every month the period touches.
     *
     * Separated from `getStats()` so `DashboardCache` wraps the queries and not the presentation — a cached
     * `Stat` object would be a cached colour and a cached sentence, which is a lot of nothing to store.
     *
     * @return array{billable: float, booked: float, people: int}
     */
    public function totals(): array
    {
        $service = app(TimesheetService::class);

        $billable = 0.0;
        $booked = 0.0;
        $people = [];

        foreach ($this->months() as $month) {
            foreach ($service->utilisation((int) $month->year, (int) $month->month) as $row) {
                $billable += (float) $row['billable_hours'];
                $booked += (float) $row['booked_hours'];
                // Counted across the whole span rather than per month, so somebody who booked in January and
                // not February is one person who recorded time and not two halves of one.
                $people[$row['employee']] = true;
            }
        }

        return ['billable' => round($billable, 2), 'booked' => round($booked, 2), 'people' => count($people)];
    }

    /**
     * The months the period touches, oldest first.
     *
     * @return array<int, Carbon>
     */
    private function months(): array
    {
        $from = Carbon::parse($this->periodFrom ?? now()->startOfMonth()->toDateString())->startOfMonth();
        $to = Carbon::parse($this->periodTo ?? now()->toDateString())->startOfMonth();

        $months = [];

        for ($month = $from->copy(); $month->lte($to); $month->addMonthNoOverflow()) {
            $months[] = $month->copy();
        }

        // A period whose ends are the wrong way round yields nothing rather than looping for ever, which is
        // the failure mode a `for` over dates has when the guard is wrong.
        return $months;
    }
}
