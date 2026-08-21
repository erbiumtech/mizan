<?php

namespace App\Modules\ConstructionField\Filament\Resources\Submittals\RelationManagers;

use App\Modules\ConstructionField\Models\Submittal;
use App\Modules\ConstructionField\Models\SubmittalReview;
use App\Modules\ConstructionField\Services\SubmittalService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * Every round this submittal has been through — §16.3.
 *
 * **A row per round, because a status column cannot hold this.** §16.3: "a submittal that has been round three times is
 * a schedule risk, and a single status column loses that fact completely." One round was budgeted for; rounds two and
 * three spend float nobody planned, and every individual step still looks reasonable while the fabrication date is
 * missed.
 *
 * The rows are read-only here. Opening and closing a round go through the register's own actions so the header's status
 * is written from the round rather than beside it — an editable round would let the two disagree, which is exactly the
 * failure the rounds exist to prevent.
 */
class ReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviews';

    protected static ?string $title = 'Review rounds';

    private function submittal(): Submittal
    {
        /** @var Submittal $submittal */
        $submittal = $this->getOwnerRecord();

        return $submittal;
    }

    public function form(Schema $schema): Schema
    {
        // Rounds are opened and closed by the register's actions, never edited here. See the class docblock.
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('round')
            ->defaultSort('round')
            ->columns([
                TextColumn::make('round')->label('Round')->badge(),

                TextColumn::make('revision')->placeholder('—'),

                TextColumn::make('reviewer_label')
                    ->label('Reviewer')
                    ->getStateUsing(fn (SubmittalReview $record): string => $record->reviewerName()),

                TextColumn::make('sent_on')->label('Sent')->date('d M Y'),

                TextColumn::make('returned_on')
                    ->label('Returned')
                    ->date('d M Y')
                    // Named rather than blank: a round still out is the fact somebody is looking for.
                    ->placeholder('still out'),

                /*
                 * Days taken against the period this round was measured on — snapshotted onto the row, so an overrun
                 * computed against fourteen days keeps saying fourteen whatever the contract is renegotiated to.
                 */
                TextColumn::make('turnaround')
                    ->label('Turnaround')
                    ->badge()
                    ->getStateUsing(fn (SubmittalReview $record): string => $record->turnaroundDays()
                        .' of '.$record->review_period_days)
                    ->color(fn (SubmittalReview $record): string => $record->hasOverrun() ? 'danger' : 'gray')
                    ->description(fn (SubmittalReview $record): ?string => $record->hasOverrun()
                        ? $record->overrunDays().' days over'
                        : null),

                TextColumn::make('result')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? 'awaited'
                        : (SubmittalReview::RESULTS[$state] ?? $state))
                    ->color(fn (SubmittalReview $record): string => match (true) {
                        $record->result === null => 'warning',
                        $record->sendsItBack() => 'danger',
                        default => 'success',
                    })
                    ->description(fn (SubmittalReview $record): ?string => $record->comments),

                TextColumn::make('delay_event_id')
                    ->label('Notified')
                    ->badge()
                    ->getStateUsing(fn (SubmittalReview $record): ?string => match (true) {
                        $record->delay_event_id !== null => $record->delayEvent?->reference,
                        $record->overrunUnnotified() => 'no notice',
                        default => null,
                    })
                    ->placeholder('—')
                    ->color(fn (SubmittalReview $record): string => $record->overrunUnnotified() ? 'danger' : 'gray'),
            ])
            ->recordActions([
                /*
                 * The claimable half, notified per round rather than per submittal.
                 *
                 * The contractor submits, so being late to submit is a risk it owns; a reviewer past their period has
                 * taken somebody else's programme, and that belongs to the round it happened in.
                 */
                Action::make('notifyOverrun')
                    ->label('Notify')
                    ->icon('heroicon-o-clock')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Notify this late review')
                    ->modalDescription('Dated the day the review period expired, not the day it came back — the notice period runs from the event.')
                    ->visible(fn (SubmittalReview $record): bool => $record->overrunUnnotified()
                        && (auth()->user()?->can('raiseDelay', $this->submittal()) ?? false))
                    ->action(function (SubmittalReview $record): void {
                        try {
                            app(SubmittalService::class)->raiseDelayForOverrun($record);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Delay event raised.')
                            ->body('Dated the day the review period expired.')
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Not yet submitted')
            ->emptyStateDescription('Each submission opens a round. The count of rounds is the schedule risk a status column cannot show.');
    }
}
