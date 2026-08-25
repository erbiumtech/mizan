<?php

namespace App\Modules\Employees\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Employees\Support\HeadcountReports;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Headcount, and who joined and left — `docs/reports-expansion-plan.md` Phase 5.2.
 *
 * **Fed by `HeadcountReports::summary()`, added for this and living beside the Headcount Movement report's own
 * loop** — so both read one definition of who counts as employed on a date. That matters more here than
 * anywhere else in Phase 5: `headcountAt()` compares date *strings* because `left_on` is a date cast and a
 * boundary is an instant, and getting it wrong once already reported 200% turnover for a month in which one
 * person of one left. A widget with its own `whereNull` would have reproduced the bug rather than inherited
 * the fix.
 *
 * **The net change is stated, not left as arithmetic.** A company that grew by ten while losing eight has a
 * story neither figure tells alone, which is the same reason the report's own note leads with it.
 */
class HeadcountOverview extends StatsOverviewWidget
{
    use WidgetBelongsToModule;

    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    /** People band — see RevenueAndExpensesChart for the scheme. */
    protected static ?int $sort = 40;

    protected static bool $isLazy = true;

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return (bool) auth()->user()?->can('EmployeeView');
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $summary = app(HeadcountReports::class)->summary(
            $this->periodFrom ?? now()->startOfMonth()->toDateString(),
            $this->periodTo ?? now()->toDateString(),
        );

        $net = $summary['headcount'] - $summary['opening'];

        return [
            Stat::make('Headcount', (string) $summary['headcount'])
                ->description(sprintf('%s%d over the period', $net >= 0 ? '+' : '', $net))
                ->color(match (true) {
                    $net > 0 => 'success',
                    $net < 0 => 'warning',
                    default => 'gray',
                }),

            Stat::make('Joiners', (string) $summary['joiners'])
                // Counted by joining date, as the report counts them, and its docblock says why: "a month's
                // joiners is a fact about that month, and re-employment is a joining".
                ->description('by joining date')
                ->color('gray'),

            Stat::make('Leavers', (string) $summary['leavers'])
                ->description('by leaving date')
                ->color($summary['leavers'] > 0 ? 'warning' : 'gray'),
        ];
    }
}
