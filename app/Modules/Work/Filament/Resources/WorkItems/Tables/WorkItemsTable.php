<?php

namespace App\Modules\Work\Filament\Resources\WorkItems\Tables;

use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every work item, as a list.
 *
 * **This is the board's query with no columns**, and it is built first on purpose: if the list
 * cannot answer "what is open, whose is it, and what is blocked", no amount of drag-and-drop on top
 * will. The board in Phase 2 is this filtered by status and drawn sideways.
 */
class WorkItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'status', 'assignee.user', 'labels']))
            ->columns([
                TextColumn::make('key')
                    ->label('Key')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (WorkItem $record): string => $record->project?->name ?? '—'),

                TextColumn::make('title')
                    ->searchable()
                    ->wrap()
                    ->description(fn (WorkItem $record): ?string => $record->parent?->display_label),

                TextColumn::make('type')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    // The colour is the category's, not the status's name: a company that invents
                    // "Waiting on client" gets the in-progress colour without being asked.
                    ->color(fn (WorkItem $record): string => match ($record->status?->category) {
                        WorkStatus::DONE => 'success',
                        WorkStatus::IN_PROGRESS => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('priority')
                    ->badge()
                    ->color(fn (?string $state): string => match (strtolower((string) $state)) {
                        'highest', 'high' => 'danger',
                        'low', 'lowest' => 'gray',
                        default => 'warning',
                    })
                    ->toggleable(),

                TextColumn::make('assignee.employee_id')
                    ->label('Assignee')
                    ->formatStateUsing(fn ($state, WorkItem $record): string => $record->assignee?->display_label ?? $state)
                    ->placeholder('Unassigned')
                    ->toggleable(),

                TextColumn::make('due_on')
                    ->label('Due')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->color(fn (WorkItem $record): ?string => $record->due_on !== null
                        && $record->due_on->isPast()
                        && ! $record->isDone() ? 'danger' : null)
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('labels.name')
                    ->label('Labels')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('estimate_points')
                    ->label('Points')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('key')
            ->filters([
                SelectFilter::make('project_id')
                    ->label('Project')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('work_status_id')
                    ->label('Status')
                    ->relationship('status', 'name'),

                /*
                 * By category rather than by status, and this is the filter that proves §4.2.
                 *
                 * "What is still open" has to keep working for a workflow nobody has written yet,
                 * which a list of status names cannot do and a category can.
                 */
                SelectFilter::make('category')
                    ->label('Where it is up to')
                    ->options(WorkStatus::CATEGORIES)
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, string $category) => $q->whereHas(
                            'status',
                            fn (Builder $s) => $s->where('category', $category),
                        ),
                    )),

                SelectFilter::make('assignee_employee_id')
                    ->label('Assignee')
                    ->relationship('assignee', 'employee_id')
                    ->searchable(),

                Filter::make('mine')
                    ->label('Assigned to me')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'assignee',
                        fn (Builder $e) => $e->where('user_id', auth()->id()),
                    )),

                Filter::make('open')
                    ->label('Open only')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->open()),

                Filter::make('blocked')
                    ->label('Blocked')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'linkedFrom',
                        fn (Builder $l) => $l->where('kind', 'blocks'),
                    )),
            ]);
    }
}
