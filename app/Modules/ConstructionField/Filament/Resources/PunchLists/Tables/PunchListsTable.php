<?php

namespace App\Modules\ConstructionField\Filament\Resources\PunchLists\Tables;

use App\Modules\ConstructionField\Models\PunchList;
use App\Modules\ConstructionField\Services\PunchListService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The lists, with the two counts that matter on every row.
 *
 * **Open** is how much work is left. **Blocking** is how much money is held: §11's AIA release holds back the cost of
 * rectifying open items that affect practical completion, so that count is the one attached to retention rather than to
 * effort.
 *
 * A list cannot be closed while items are open, and the refusal names the count — "three items outstanding" is
 * actionable where "cannot close" is not.
 */
class PunchListsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->wrap()
                    ->searchable()
                    ->description(fn (PunchList $record): string => $record->kindLabel()
                        .($record->job?->code ? " · {$record->job->code}" : '')),

                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => PunchList::KINDS[$state] ?? $state)
                    // The contractor's own sweep is marked differently, because it is not a document to hand over.
                    ->color(fn (string $state): string => $state === PunchList::KIND_INTERNAL ? 'gray' : 'info')
                    ->sortable(),

                TextColumn::make('location.name')
                    ->label('Area')
                    ->getStateUsing(fn (PunchList $record): ?string => $record->location?->fullName())
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('opened_on')->label('Walked')->date('d M Y')->sortable(),

                TextColumn::make('target_completion_date')
                    ->label('Clear by')
                    ->date('d M Y')
                    ->placeholder('no date')
                    ->toggleable(),

                TextColumn::make('open_items')
                    ->label('Open')
                    ->badge()
                    ->getStateUsing(fn (PunchList $record): string => (string) $record->openItems())
                    ->color(fn (PunchList $record): string => $record->openItems() > 0 ? 'warning' : 'success'),

                /*
                 * **The count attached to money.** §11's AIA release holds back the rectification cost of exactly these,
                 * so it is shown apart from the open count rather than folded into it.
                 */
                TextColumn::make('blocking')
                    ->label('Blocking')
                    ->badge()
                    ->getStateUsing(fn (PunchList $record): ?string => $record->blockingItems() > 0
                        ? (string) $record->blockingItems()
                        : null)
                    ->placeholder('—')
                    ->color('danger')
                    ->tooltip('Open items marked as affecting practical completion. Retention is held back against these.'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === PunchList::STATUS_CLOSED ? 'success' : 'warning')
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('job_id')->label('Job')->relationship('job', 'code')->searchable(),

                SelectFilter::make('kind')->options(PunchList::KINDS),

                Filter::make('open')
                    ->label('Still open')
                    ->query(fn (Builder $query): Builder => $query->open())
                    ->toggle(),

                Filter::make('external')
                    ->label('Not internal sweeps')
                    ->query(fn (Builder $query): Builder => $query->external())
                    ->toggle(),
            ])
            ->defaultSort('opened_on', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (PunchList $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('close')
                    ->label('Close list')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('A closed list with open items on it is a handover certificate nobody should have signed, so this is refused while anything is outstanding.')
                    ->visible(fn (PunchList $record): bool => auth()->user()?->can('close', $record) ?? false)
                    ->action(function (PunchList $record): void {
                        try {
                            app(PunchListService::class)->closeList($record);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()->success()->title('List closed.')->send();
                    }),

                DeleteAction::make()
                    // Only while empty: a list with items on it is the record of a walk-round.
                    ->visible(fn (PunchList $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->emptyStateHeading('No punch lists')
            ->emptyStateDescription('Open one per walk-round. The items that affect practical completion are what retention is held back against.');
    }
}
