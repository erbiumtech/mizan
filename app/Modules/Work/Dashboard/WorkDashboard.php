<?php

namespace App\Modules\Work\Dashboard;

use App\Modules\Work\Filament\Pages\Board;
use App\Modules\Work\Models\WorkItem;
use App\Support\DashboardSummaries;

/** The open work items card on the Material dashboard — registered here so the dashboard names no module. */
final class WorkDashboard
{
    public static function register(): void
    {
        DashboardSummaries::register(
            'work.open',
            visible: fn (): bool => modules()->enabled('work') && (bool) auth()->user()?->can('WorkItemView'),
            value: fn (): array => [
                'label' => __('Open work items'),
                'value' => (string) WorkItem::query()->open()->count(),
                'link' => __('Open board'),
                'url' => rescue(fn (): string => Board::getUrl(), null, false),
                'tone' => 'primary',
            ],
            sort: 10,
        );
    }
}
