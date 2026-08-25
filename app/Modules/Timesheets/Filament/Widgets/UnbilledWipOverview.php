<?php

namespace App\Modules\Timesheets\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Timesheets\Services\TimesheetService;
use App\Support\Reporting\ReportFigures;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Billable time recorded and not yet invoiced — `docs/reports-expansion-plan.md` Phase 5.4.
 *
 * **Fed by `TimesheetService::unbilledWip()`, the service behind the Unbilled WIP report.** The same call, so
 * the dashboard figure and the report's total are the same number by construction rather than by agreement.
 *
 * **An as-at, not a span.** Work in progress is a balance: everything billable, unbilled and recorded on or
 * before a date. So the dashboard's period sets the date and not a window — reading as at a year end gives
 * the WIP that stood at the year end, which is the figure somebody accrues against.
 *
 * **Unpriced hours are stated separately, because they are the finding.** The report's own point is that time
 * with no rate against it cannot be valued: it is real work that will be invoiced at a number nobody has
 * decided yet, and folding it into the amount would understate the balance while looking complete. Phase 2's
 * note about this report is that nothing posts it — there is no ledger balance to tie to — which is exactly
 * why the unpriced figure has to be visible rather than absorbed.
 */
class UnbilledWipOverview extends StatsOverviewWidget
{
    use WidgetBelongsToModule;

    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    /** Service band — see RevenueAndExpensesChart for the scheme. */
    protected static ?int $sort = 32;

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
        $wip = app(TimesheetService::class)->unbilledWip($this->periodTo);

        $projects = count($wip['projects'] ?? []);
        $unpriced = (float) ($wip['unpriced_hours'] ?? 0);

        return [
            Stat::make('Unbilled WIP', ReportFigures::money($wip['amount'] ?? 0))
                ->description(sprintf(
                    '%s hours across %d %s',
                    ReportFigures::money($wip['hours'] ?? 0, 1),
                    $projects,
                    $projects === 1 ? 'project' : 'projects',
                ))
                ->color('primary'),

            Stat::make('Unpriced hours', ReportFigures::money($unpriced, 1))
                ->description($unpriced > 0
                    ? 'billable time with no rate, so not in the amount'
                    : 'every billable hour has a rate')
                ->color($unpriced > 0 ? 'warning' : 'gray'),
        ];
    }
}
