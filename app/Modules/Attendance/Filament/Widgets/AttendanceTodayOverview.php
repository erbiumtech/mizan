<?php

namespace App\Modules\Attendance\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Attendance\Services\AttendanceRegister;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Present, late and on leave — `docs/reports-expansion-plan.md` Phase 5.2.
 *
 * "Present / late / on leave today."
 *
 * **The plan says today and this reads the period's end, which is the same thing on an unfiltered
 * dashboard.** Attendance is a fact about a day, so the period sets *which* day rather than a window — and
 * reading the dashboard as at a past date then answers "who was in that day", which is a question worth
 * being able to ask. On the default period the end is today, so the plan's wording holds.
 *
 * **Fed by `AttendanceRegister::daySummary()`, added for this**, and it is one grouped query rather than the
 * register's per-employee grid — which for four numbers about one day would be the per-row shape this plan's
 * risk list names.
 *
 * **Unmarked days are on the widget, because they are the finding.** A day nobody recorded is not a day
 * nobody worked. "12 present" for a company of thirty, with eighteen absent from every figure, reads as an
 * attendance problem rather than a recording one — and the register's own note leads with unmarked days for
 * exactly that reason.
 */
class AttendanceTodayOverview extends StatsOverviewWidget
{
    use WidgetBelongsToModule;

    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    /** People band — see RevenueAndExpensesChart for the scheme. */
    protected static ?int $sort = 41;

    protected static bool $isLazy = true;

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return (bool) auth()->user()?->can('AttendanceView');
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $on = $this->periodTo ?? now()->toDateString();
        $day = app(AttendanceRegister::class)->daySummary($on);

        $label = Carbon::parse($on)->isToday() ? 'today' : Carbon::parse($on)->format('j M Y');

        return [
            Stat::make('Present', (string) $day['present'])
                ->description($day['late'] > 0 ? $day['late'].' of them late · '.$label : 'none late · '.$label)
                ->color($day['present'] > 0 ? 'success' : 'gray'),

            Stat::make('On leave', (string) $day['on_leave'])
                ->description($label)
                ->color('gray'),

            Stat::make('Not marked', (string) $day['unmarked'])
                ->description($day['unmarked'] > 0
                    ? 'nobody recorded these, so they are in no figure above'
                    : 'every day is accounted for')
                ->color($day['unmarked'] > 0 ? 'warning' : 'gray'),
        ];
    }
}
