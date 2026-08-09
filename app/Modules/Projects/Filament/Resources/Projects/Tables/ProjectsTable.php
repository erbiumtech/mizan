<?php

namespace App\Modules\Projects\Filament\Resources\Projects\Tables;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectEnvironment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->header(view('filament.tables.saved-views-bar'))
            ->columns([
                TextColumn::make('code')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Project::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Project::STATUS_ACTIVE => 'success',
                        Project::STATUS_PLANNED => 'info',
                        Project::STATUS_ON_HOLD => 'warning',
                        Project::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('manager.employee_id')
                    ->label('Manager')
                    ->formatStateUsing(fn ($state, Project $record): string => $record->manager?->display_label ?? '—')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'manager',
                        fn (Builder $q) => $q->where('employee_id', 'like', "%{$search}%")
                    ))
                    ->placeholder('—'),

                TextColumn::make('secondaryManager.employee_id')
                    ->label('Secondary')
                    ->formatStateUsing(fn ($state, Project $record): string => $record->secondaryManager?->display_label ?? '—')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('environments')
                    ->label('Environments')
                    ->badge()
                    ->state(fn (Project $record): array => $record->environments
                        ->sortBy(fn (ProjectEnvironment $env) => array_search($env->kind, array_keys(ProjectEnvironment::KINDS), true))
                        ->map(fn (ProjectEnvironment $env) => $env->kind)
                        ->values()
                        ->all())
                    ->color(fn (string $state, Project $record): string => match ($record->environments->firstWhere('kind', $state)?->health_status) {
                        ProjectEnvironment::HEALTH_UP => 'success',
                        ProjectEnvironment::HEALTH_DOWN => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (Project $record): ?string => self::healthTooltip($record))
                    ->placeholder('—'),

                TextColumn::make('employees_count')
                    ->label('Team')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('start_date')->date()->sortable()->placeholder('—'),

                TextColumn::make('end_date')->date()->sortable()->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                ...CustomFieldsSchema::tableColumns(Project::class),
            ])
            ->groups([
                Group::make('status')->label('Status'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Project::STATUSES)
                    ->multiple(),

                SelectFilter::make('manager_employee_id')
                    ->label('Manager')
                    ->relationship('manager', 'employee_id')
                    ->searchable()
                    ->preload(),

                Filter::make('unhealthy')
                    ->label('Has an environment down')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'environments',
                        fn (Builder $q) => $q->where('health_status', ProjectEnvironment::HEALTH_DOWN)
                    )),

                Filter::make('never_checked')
                    ->label('Never health-checked')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'environments',
                        fn (Builder $q) => $q->monitorable()->whereNull('health_checked_at')
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make(self::openEnvironmentActions())
                    ->label('Open')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->button()
                    ->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('code');
    }

    /**
     * One link per configured environment URL. Built from the record at render
     * time so a project with no URLs shows an empty group rather than a
     * misleading link.
     *
     * @return array<Action>
     */
    protected static function openEnvironmentActions(): array
    {
        return collect(ProjectEnvironment::KINDS)
            ->map(fn (string $label, string $kind) => Action::make("open_{$kind}")
                ->label($label)
                ->icon('heroicon-m-globe-alt')
                ->visible(fn (Project $record): bool => filled($record->environments->firstWhere('kind', $kind)?->url))
                ->url(fn (Project $record): ?string => $record->environments->firstWhere('kind', $kind)?->url, shouldOpenInNewTab: true))
            ->values()
            ->all();
    }

    protected static function healthTooltip(Project $record): ?string
    {
        $lines = $record->environments
            ->filter(fn (ProjectEnvironment $env) => $env->health_checked_at !== null)
            ->map(function (ProjectEnvironment $env): string {
                $parts = [$env->label().': '.($env->health_status ?? 'unknown')];

                if ($env->health_code) {
                    $parts[] = 'HTTP '.$env->health_code;
                }

                if ($env->health_latency_ms !== null) {
                    $parts[] = $env->health_latency_ms.'ms';
                }

                $parts[] = 'checked '.$env->health_checked_at->diffForHumans();

                return implode(' · ', $parts);
            });

        return $lines->isEmpty() ? null : $lines->implode("\n");
    }
}
