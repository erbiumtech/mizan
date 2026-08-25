<?php

namespace App\Modules\Projects\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Projects\Filament\Resources\Projects\ProjectResource;
use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Models\ProjectEnvironmentIncident;
use App\Support\Reporting\DashboardWidgets;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Open, confirmed outages. The widget people actually read — so it stays empty
 * and quiet when nothing is wrong.
 */
class EnvironmentIncidentsTable extends TableWidget
{
    use WidgetBelongsToModule;

    protected static bool $isLazy = true;

    /**
     * No polling — `docs/reports-expansion-plan.md` Phase 5.7 asks for it and Filament's default is against
     * it: `CanPoll::$pollingInterval` is `'5s'`, so every widget in this panel was re-running its aggregates
     * every five seconds, per open tab, unasked. On a dashboard of twenty-three widgets that is the cost
     * Phase 5.8's cache exists to avoid, incurred twelve times a minute instead of once a page.
     */
    protected ?string $pollingInterval = null;

    /** The open incidents behind the figure above it. */
    protected static ?int $sort = DashboardWidgets::SERVICE + 5;

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return 'Environments down';
    }

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return (bool) auth()->user()?->can('ProjectView');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ProjectEnvironmentIncident::query()
                    ->open()
                    ->confirmed()
                    ->with(['environment.project'])
            )
            ->columns([
                TextColumn::make('environment.project.name')
                    ->label('Project')
                    ->url(fn (ProjectEnvironmentIncident $record): ?string => $record->environment?->project
                        ? ProjectResource::getUrl('view', ['record' => $record->environment->project_id])
                        : null),

                TextColumn::make('environment.kind')
                    ->label('Environment')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (?string $state): string => ProjectEnvironment::KINDS[$state] ?? (string) $state),

                TextColumn::make('started_at')
                    ->label('Down for')
                    ->formatStateUsing(fn ($state, ProjectEnvironmentIncident $record): string => $record->durationForHumans()),

                TextColumn::make('failure_count')->label('Failed checks'),

                TextColumn::make('last_status_code')->label('HTTP')->placeholder('—'),

                TextColumn::make('last_error')
                    ->label('Last error')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(80),
            ])
            ->defaultSort('started_at')
            ->emptyStateHeading('All environments healthy')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated([5, 10, 25]);
    }
}
