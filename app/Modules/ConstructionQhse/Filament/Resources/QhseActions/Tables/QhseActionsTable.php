<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\QhseActions\Tables;

use App\Modules\ConstructionQhse\Models\QhseAction;
use App\Modules\ConstructionQhse\Services\ActionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Everything outstanding, from every source, in one list — §17.4.
 *
 * **The `From` column is the reason this is one table.** An NCR, an inspection, an incident and a toolbox talk all
 * produce the same record, and a register that showed them in four places would be four reports that never agree.
 *
 * **`Done` and `Verified` are two columns because they are two acts.** An action showing *awaiting verification* is one
 * somebody has claimed and nobody has checked — a state a register with only "closed" would lose entirely, and the state
 * where most quality systems quietly fail.
 */
class QhseActionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject_type')
                    ->label('From')
                    ->badge()
                    ->getStateUsing(fn (QhseAction $record): string => $record->sourceLabel())
                    ->description(fn (QhseAction $record): ?string => $record->subject?->displayName()
                        // Named rather than blank: an action whose finding has gone is a row worth seeing.
                        ?? 'the finding is no longer present')
                    ->color('gray'),

                TextColumn::make('description')
                    ->wrap()
                    ->limit(60)
                    ->searchable()
                    ->description(fn (QhseAction $record): ?string => $record->job?->code),

                TextColumn::make('action_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn (string $state): string => $state === 'containment' ? 'warning' : 'gray')
                    ->tooltip('Containment stops it spreading now; corrective fixes it; preventive stops it recurring.'),

                TextColumn::make('assignee_label')
                    ->label('Who')
                    ->getStateUsing(fn (QhseAction $record): string => $record->assigneeName())
                    ->color(fn (QhseAction $record): string => $record->isUnassigned() ? 'danger' : 'gray'),

                TextColumn::make('priority')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => QhseAction::PRIORITIES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('due_on')
                    ->label('Due')
                    ->date('d M Y')
                    // Named: an action with no date is one nobody is chasing.
                    ->placeholder('no date set')
                    ->color(fn (QhseAction $record): string => $record->isOverdue() ? 'danger' : 'gray')
                    ->description(fn (QhseAction $record): ?string => $record->isOverdue()
                        ? abs((int) $record->daysUntilDue()).' days late'
                        : null)
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => QhseAction::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        QhseAction::STATUS_VERIFIED => 'success',
                        QhseAction::STATUS_DONE => 'info',
                        QhseAction::STATUS_CANCELLED => 'gray',
                        default => 'warning',
                    })
                    ->description(fn (QhseAction $record): ?string => $record->cancel_reason)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('job_id')->label('Job')->relationship('job', 'code')->searchable(),
                SelectFilter::make('status')->options(QhseAction::STATUSES),
                SelectFilter::make('priority')->options(QhseAction::PRIORITIES),
                SelectFilter::make('action_type')->options(QhseAction::TYPES),

                Filter::make('overdue')
                    ->label('Overdue')
                    ->query(fn (Builder $query): Builder => $query->overdue())
                    ->toggle(),

                Filter::make('awaiting_verification')
                    ->label('Done, awaiting verification')
                    ->query(fn (Builder $query): Builder => $query->awaitingVerification())
                    ->toggle(),

                Filter::make('unassigned')
                    ->label('Nobody assigned')
                    ->query(fn (Builder $query): Builder => $query->live()
                        ->whereNull('assignee_label')
                        ->whereNull('assigned_contact_id')
                        ->whereNull('assigned_user_id'))
                    ->toggle(),
            ])
            ->defaultSort('due_on')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (QhseAction $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('start')
                    ->label('Start')
                    ->icon('heroicon-o-play')
                    ->color('gray')
                    ->visible(fn (QhseAction $record): bool => (auth()->user()?->can('update', $record) ?? false)
                        && $record->status === QhseAction::STATUS_OPEN)
                    ->action(fn (QhseAction $record) => static::run(
                        fn () => app(ActionService::class)->start($record),
                        'Started.',
                        'Which is worth distinguishing from nobody having looked at it.',
                    )),

                /*
                 * **The claim**, not the closure.
                 */
                Action::make('complete')
                    ->label('Mark done')
                    ->icon('heroicon-o-check')
                    ->color('info')
                    ->modalHeading('Mark it done')
                    ->modalDescription('This is your claim that it is finished. It stays on the list as awaiting verification until somebody else confirms it — because an actions register where the person who caused a finding can close it is one nobody reads.')
                    ->schema([
                        DatePicker::make('on')->label('Done on')->native(false)->default(now()),
                        Textarea::make('notes')->rows(2),
                    ])
                    ->visible(fn (QhseAction $record): bool => (auth()->user()?->can('complete', $record) ?? false)
                        && ! $record->isDone())
                    ->action(fn (QhseAction $record, array $data) => static::run(
                        fn () => app(ActionService::class)->complete($record, $data['on'] ?? null, $data['notes'] ?? null),
                        'Marked done.',
                        'Still on the list until somebody verifies it.',
                    )),

                /*
                 * **Somebody else's confirmation**, and its own permission.
                 */
                Action::make('verify')
                    ->label('Verify')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->modalHeading('Verify it was done')
                    ->modalDescription('Refused on an action nobody has claimed to have done — verifying something that has not happened is a closure that proves nothing.')
                    ->schema([
                        DatePicker::make('on')->label('Verified on')->native(false)->default(now()),
                        Textarea::make('notes')->rows(2),
                    ])
                    ->visible(fn (QhseAction $record): bool => auth()->user()?->can('verify', $record) ?? false)
                    ->action(fn (QhseAction $record, array $data) => static::run(
                        fn () => app(ActionService::class)->verify($record, $data['on'] ?? null, $data['notes'] ?? null),
                        'Verified.',
                        'Your name is against it.',
                    )),

                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->modalDescription('Kept with the reason rather than deleted — a row that simply disappears is the one that gets raised again next month.')
                    ->schema([
                        Textarea::make('reason')->label('Why')->rows(2)->required(),
                    ])
                    ->visible(fn (QhseAction $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->action(fn (QhseAction $record, array $data) => static::run(
                        fn () => app(ActionService::class)->cancel($record, $data['reason']),
                        'Cancelled.',
                        'The reason is on the row.',
                    )),
            ])
            ->emptyStateHeading('No actions')
            ->emptyStateDescription('Actions are raised against the finding that produced them — an NCR, an inspection, an incident. This is where they are all read together.');
    }

    /** Service refusals are sentences somebody needs to read, so they are surfaced rather than thrown. */
    private static function run(callable $call, string $title, string $body): void
    {
        try {
            $call();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            return;
        }

        Notification::make()->success()->title($title)->body($body)->send();
    }
}
