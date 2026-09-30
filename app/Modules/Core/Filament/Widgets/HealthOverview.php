<?php

namespace App\Modules\Core\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Spatie\Health\ResultStores\EloquentHealthResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;

/**
 * The installation's health, on the dashboard — a glance at what the /ops/health
 * page reports in full: backups, disk, Horizon, mail, the tenant databases.
 *
 * **Super admins only, and checked directly rather than through a Gate.** These
 * checks are installation-wide — one dashboard for every tenant at once — which is
 * the same data-protection line the /ops/health route and the Horizon gate draw,
 * for the same reason: AppServiceProvider's `Gate::before` answers every
 * non-create ability with `true` for any company Administrator, so a gate here
 * would show one customer's admin the state of everyone's backups. `isSuperAdmin()`
 * is the installation-level authority; nothing company-scoped substitutes for it.
 *
 * Reads the stored results the scheduled `health:check` writes (every minute) — it
 * never runs the checks itself, so opening a dashboard costs one query, not a disk
 * stat and a Redis ping and a connection per tenant. A stale or absent last-run is
 * itself surfaced: "checks that fail to run" is the failure the whole health system
 * exists to make visible, and a green tile over a dead scheduler would hide it.
 */
class HealthOverview extends StatsOverviewWidget
{
    // First on the page for the one person who sees it: ops before figures.
    protected static ?int $sort = -100;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    protected function getStats(): array
    {
        $results = app(EloquentHealthResultStore::class)->latestResults();

        if ($results === null) {
            return [
                Stat::make('System health', 'Not yet checked')
                    ->description('The health schedule has not written a result. Run php artisan health:check.')
                    ->color('gray')
                    ->url('/ops/health', shouldOpenInNewTab: true),
            ];
        }

        $checks = $results->storedCheckResults;
        $failed = $checks->filter(fn (StoredCheckResult $r): bool => in_array($r->status, ['failed', 'crashed'], true));
        $warning = $checks->filter(fn (StoredCheckResult $r): bool => $r->status === 'warning');
        $ok = $checks->count() - $failed->count() - $warning->count();

        // The schedule runs every minute; anything older than a few minutes means it
        // has stopped, which is a red flag in itself no matter what the last run said.
        $finishedAt = Carbon::instance($results->finishedAt);
        $stale = $finishedAt->lt(now()->subMinutes(10));

        return [
            $this->overallStat($failed->count(), $warning->count(), $ok, $stale),
            $this->attentionStat($failed, $warning),
            Stat::make('Last checked', $finishedAt->diffForHumans())
                ->description($stale ? 'The health schedule may have stopped' : 'Updated by the scheduler each minute')
                ->color($stale ? 'danger' : 'gray')
                ->url('/ops/health', shouldOpenInNewTab: true),
        ];
    }

    private function overallStat(int $failed, int $warning, int $ok, bool $stale): Stat
    {
        [$value, $color] = match (true) {
            $stale => ['Stale', 'danger'],
            $failed > 0 => [$failed.' failing', 'danger'],
            $warning > 0 => [$warning.' need attention', 'warning'],
            default => ['All systems OK', 'success'],
        };

        return Stat::make('System health', $value)
            ->description($ok.' of '.($ok + $warning + $failed).' checks passing')
            ->color($color)
            ->url('/ops/health', shouldOpenInNewTab: true);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, StoredCheckResult>  $failed
     * @param  \Illuminate\Support\Collection<int, StoredCheckResult>  $warning
     */
    private function attentionStat($failed, $warning): Stat
    {
        $names = $failed->merge($warning)
            ->map(fn (StoredCheckResult $r): string => $r->label ?: $r->name)
            ->take(4)
            ->implode(', ');

        return Stat::make('Needs attention', $names !== '' ? $names : 'Nothing')
            ->description($names !== '' ? 'Open the health page for detail' : 'Every check is green')
            ->color($names !== '' ? 'warning' : 'success')
            ->url('/ops/health', shouldOpenInNewTab: true);
    }
}
