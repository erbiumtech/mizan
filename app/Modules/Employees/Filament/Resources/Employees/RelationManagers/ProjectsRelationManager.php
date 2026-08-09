<?php

namespace App\Modules\Employees\Filament\Resources\Employees\RelationManagers;

use App\Modules\Projects\Filament\Resources\Projects\ProjectResource;
use App\Modules\Projects\Models\Project;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The employee's project assignments. Read-only on purpose — assignments are
 * managed from the project side, so there is one write path to keep correct
 * (same precedent as ChangeRequestsRelationManager).
 */
class ProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    protected static ?string $title = 'Projects';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('ProjectView') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Project::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Project::STATUS_ACTIVE => 'success',
                        Project::STATUS_PLANNED => 'info',
                        Project::STATUS_ON_HOLD => 'warning',
                        Project::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('pivot.role')
                    ->label('Project role')
                    ->placeholder('—'),

                TextColumn::make('pivot.allocation_pct')
                    ->label('Allocation')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'%'),

                TextColumn::make('pivot.from_date')->label('From')->date(),

                TextColumn::make('pivot.to_date')->label('To')->date()->placeholder('open'),

                TextColumn::make('stint_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Project $record): string => self::isCurrent($record) ? 'current' : 'ended')
                    ->color(fn (string $state): string => $state === 'current' ? 'success' : 'gray'),
            ])
            ->recordUrl(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record]))
            ->defaultSort('project_employee.from_date', 'desc')
            ->emptyStateHeading('Not assigned to any project');
    }

    protected static function isCurrent(Project $record): bool
    {
        $to = $record->pivot?->to_date;

        return $to === null || $to >= today()->toDateString();
    }
}
