<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ProjectEnvironment;
use App\Models\ProjectEnvironmentIncident;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Environment health at a glance. Gated on ProjectView, which every employee
 * holds, so this is the one dashboard block everybody sees.
 */
class EnvironmentHealthOverview extends StatsOverviewWidget
{
    protected static bool $isLazy = true;

    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('ProjectView');
    }

    protected function getStats(): array
    {
        $counts = ProjectEnvironment::query()
            ->monitorable()
            ->selectRaw('health_status, COUNT(*) as aggregate')
            ->groupBy('health_status')
            ->pluck('aggregate', 'health_status');

        $up = (int) ($counts[ProjectEnvironment::HEALTH_UP] ?? 0);
        $down = (int) ($counts[ProjectEnvironment::HEALTH_DOWN] ?? 0);
        // A null health_status means "never checked" — reported as unknown
        // rather than folded into "up", so a dead scheduler stays visible.
        $unknown = ProjectEnvironment::query()->monitorable()->whereNull('health_status')->count()
            + (int) ($counts[ProjectEnvironment::HEALTH_UNKNOWN] ?? 0);

        $openIncidents = ProjectEnvironmentIncident::query()->open()->confirmed()->count();

        return [
            Stat::make('Environments up', $up)
                ->description('Answering as expected')
                ->color('success'),

            Stat::make('Environments down', $down)
                ->description($down > 0 ? 'Needs attention' : 'None')
                ->color($down > 0 ? 'danger' : 'gray')
                ->url($down > 0 ? ProjectResource::getUrl('index', ['tableFilters' => ['unhealthy' => ['isActive' => true]]]) : null),

            Stat::make('Never checked', $unknown)
                ->description($unknown > 0 ? 'Scheduler or worker may be idle' : 'All monitored')
                ->color($unknown > 0 ? 'warning' : 'gray'),

            Stat::make('Open incidents', $openIncidents)
                ->description('Confirmed and unresolved')
                ->color($openIncidents > 0 ? 'danger' : 'gray'),
        ];
    }
}
