<?php

namespace App\Modules\Leave\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Leave\Models\LeaveRequest;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Leave requests nobody has answered — `docs/reports-expansion-plan.md` Phase 5.2.
 *
 * **Counted through `LeaveRequest::pending()`, the model's own scope**, which is where "awaiting a decision"
 * is defined for the whole application. There is no service method to add here: the scope *is* the shared
 * definition, and a widget writing `where('status', 'pending')` would be a second copy of it that a fourth
 * status would silently break.
 *
 * **A queue, not a period figure — so this one ignores the dashboard's period.** A request awaiting an answer
 * is awaiting it now, whatever span somebody is reading; filtering by the period would hide the oldest
 * requests, which are the ones the widget exists to surface. That makes it the third widget on this dashboard
 * the filter does not touch, and it says so on the stat.
 *
 * **The oldest is named, because a queue of five is a different problem from one request sitting for a
 * month.** A count alone cannot tell those apart, and the second is the one somebody has to answer today.
 */
class LeaveAwaitingDecisionOverview extends StatsOverviewWidget
{
    use WidgetBelongsToModule;

    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    /** People band — see RevenueAndExpensesChart for the scheme. */
    protected static ?int $sort = 42;

    protected static bool $isLazy = true;

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return (bool) auth()->user()?->can('LeaveRequestView');
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        // `from_date`, which is what the column is called. Written as `start_date` first, and because an
        // absent attribute reads as null the "already started" figure below was silently always nought —
        // the shape of bug that passes every structural assertion.
        $pending = LeaveRequest::query()->pending()->get(['id', 'created_at', 'from_date']);

        $oldest = $pending->min('created_at');
        $waiting = $oldest === null ? null : (int) Carbon::parse($oldest)->diffInDays(now());

        // Requests whose leave has already started while nobody answered. The sharpest row in the queue:
        // somebody is either off without approval or at work when they expected not to be.
        $alreadyStarted = $pending
            ->filter(fn ($request): bool => $request->from_date !== null
                && Carbon::parse($request->from_date)->toDateString() <= now()->toDateString())
            ->count();

        return [
            Stat::make('Leave awaiting a decision', (string) $pending->count())
                // Said on the stat, because two of the three figures beside it on this dashboard do move with
                // the period and a reader would otherwise assume this one does.
                ->description($pending->isEmpty()
                    ? 'nothing is waiting'
                    : 'a queue, so whatever the period')
                ->color($pending->isEmpty() ? 'gray' : 'warning'),

            Stat::make('Longest wait', $waiting === null ? '—' : $waiting.' days')
                ->description($waiting === null ? 'nothing is waiting' : 'since the request was made')
                ->color(match (true) {
                    $waiting === null => 'gray',
                    $waiting >= 7 => 'danger',
                    default => 'gray',
                }),

            Stat::make('Already started', (string) $alreadyStarted)
                ->description($alreadyStarted > 0
                    ? 'leave began before anybody answered'
                    : 'nothing has started unanswered')
                ->color($alreadyStarted > 0 ? 'danger' : 'gray'),
        ];
    }
}
