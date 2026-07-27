<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ProjectEnvironment;
use App\Models\ProjectEnvironmentIncident;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Open, confirmed outages. The widget people actually read — so it stays empty
 * and quiet when nothing is wrong.
 */
class EnvironmentIncidentsTable extends TableWidget
{
    protected static bool $isLazy = true;

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return 'Environments down';
    }

    public static function canView(): bool
    {
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
