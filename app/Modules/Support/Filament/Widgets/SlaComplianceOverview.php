<?php

namespace App\Modules\Support\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Support\Services\TicketService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * SLA compliance over the period, and what is breaching right now — Phase 5.4.
 *
 * "SLA compliance this month with breaches outstanding now."
 *
 * **Both figures come from `TicketService`, which is the service behind the two SLA reports** —
 * `performance()` for the rate and `breaches()` for the outstanding ones. Phase 5's rule, and the pairing the
 * plan names by method.
 *
 * **The two halves read the period differently, and the plan's own wording says so.** Compliance is "this
 * month" — a rate over a window, so it takes the dashboard's period. Breaches are "outstanding **now**", and
 * `breaches()` takes no arguments at all because an open ticket breaching its SLA is a fact about this
 * moment: a breach that was outstanding in March and has since been resolved is not something to act on
 * today. So the period filter moves one stat and not the other, which is stated on the stats themselves
 * rather than left to be inferred.
 *
 * **Resolution, not response, is the headline.** `performance()` reports both; a company that answers within
 * the hour and fixes nothing has met its response commitment and failed its customer. Response is on the
 * description, because it is the half that shows a queue going unattended.
 */
class SlaComplianceOverview extends StatsOverviewWidget
{
    use WidgetBelongsToModule;

    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    /** Service band — see RevenueAndExpensesChart for the scheme. */
    protected static ?int $sort = 30;

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
        $service = app(TicketService::class);

        $rows = $service->performance($this->periodFrom, $this->periodTo);

        $tickets = array_sum(array_column($rows, 'tickets'));
        $metResolution = array_sum(array_column($rows, 'met_resolution'));
        $metResponse = array_sum(array_column($rows, 'met_response'));

        // Counted here rather than taken from `performance()`, because the two answer different questions:
        // that one is a rate over a closed window and this is what is open and late at this instant.
        $outstanding = $service->breaches()->count();

        return [
            Stat::make('Resolved in time', $this->rate($metResolution, $tickets))
                ->description($tickets === 0
                    ? 'no tickets in this period'
                    : sprintf('%d of %d tickets · %s answered in time', $metResolution, $tickets, $this->rate($metResponse, $tickets)))
                ->color(match (true) {
                    $tickets === 0 => 'gray',
                    $metResolution === $tickets => 'success',
                    $metResolution / $tickets < 0.9 => 'danger',
                    default => 'warning',
                }),

            Stat::make('Breaching now', (string) $outstanding)
                // Said plainly, because it is the one figure on this widget the period does not move — and a
                // reader comparing it against the rate beside it would otherwise assume it did.
                ->description($outstanding === 0
                    ? 'nothing open is past its commitment'
                    : 'open tickets past their commitment, whatever the period')
                ->color($outstanding === 0 ? 'success' : 'danger'),
        ];
    }

    /**
     * A percentage, or a dash where there is nothing to rate.
     *
     * No tickets is not nought per cent compliance — it is a quiet month, and reporting it as a total failure
     * would put a red figure on the dashboard of every company that had a good one.
     */
    private function rate(int $met, int $total): string
    {
        return $total === 0 ? '—' : number_format($met / $total * 100, 1).'%';
    }
}
