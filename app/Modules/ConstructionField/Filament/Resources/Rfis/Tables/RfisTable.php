<?php

namespace App\Modules\ConstructionField\Filament\Resources\Rfis\Tables;

use App\Modules\ConstructionField\Models\Rfi;
use App\Modules\ConstructionField\Services\RfiService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The register, built around the two questions §16.2 says it exists to answer.
 *
 * **Days** is the column that carries it, and it changes meaning with the row on purpose: days remaining while an answer
 * is owed, days *late* once it is past due, and the response time once it has been answered. Three separate columns
 * would each be blank two-thirds of the time; one column that says which figure it is showing reads at a glance.
 *
 * **Notified** is the register's exposure column, and it is the same shape as the diary's. A stated time impact with no
 * delay event behind it is a notice period running with nothing chasing it — and unlike a wrong figure, this failure
 * leaves nothing anywhere for a report to catch.
 */
class RfisTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rfi_number')
                    ->label('No.')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('subject')
                    ->wrap()
                    ->limit(60)
                    ->searchable()
                    ->description(fn (Rfi $record): ?string => $record->job?->code),

                TextColumn::make('ball_in_court')
                    ->label('With')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Rfi::COURTS[$state] ?? $state)
                    ->color(fn (Rfi $record): string => $record->ball_in_court === 'contractor' ? 'gray' : 'info')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Rfi::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Rfi::STATUS_CLOSED => 'success',
                        Rfi::STATUS_ANSWERED => 'info',
                        Rfi::STATUS_CANCELLED => 'gray',
                        default => 'warning',
                    })
                    ->description(fn (Rfi $record): ?string => $record->cancel_reason)
                    ->sortable(),

                TextColumn::make('required_by')
                    ->label('Needed by')
                    ->date('d M Y')
                    // Named rather than blank: an RFI with no date is one nobody is chasing.
                    ->placeholder('no date set')
                    ->sortable(),

                /*
                 * One column, three meanings, each labelled. Computed every time — §16.2's closing line is that days
                 * open, overdue and response time are all computed, and a stored days-open is wrong by one every
                 * midnight.
                 */
                TextColumn::make('days')
                    ->label('Days')
                    ->badge()
                    ->getStateUsing(function (Rfi $record): string {
                        if ($record->isAnswered()) {
                            return $record->responseDays().' to answer';
                        }

                        if ($record->isOverdue()) {
                            return abs((int) $record->daysUntilRequired()).' late';
                        }

                        $left = $record->daysUntilRequired();

                        return $left === null
                            ? $record->daysOpen().' open'
                            : $left.' left';
                    })
                    ->color(fn (Rfi $record): string => match (true) {
                        $record->isOverdue() => 'danger',
                        $record->answeredLate() => 'warning',
                        $record->isAnswered() => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('cost_impact_flag')
                    ->label('Cost')
                    ->badge()
                    ->formatStateUsing(fn (string $state, Rfi $record): string => $state === Rfi::IMPACT_NONE
                        ? '—'
                        : (Rfi::IMPACTS[$state] ?? $state)
                            .($record->cost_impact_estimate !== null
                                ? ' '.number_format((float) $record->cost_impact_estimate)
                                : ''))
                    ->color(fn (string $state): string => $state === Rfi::IMPACT_YES ? 'danger' : 'gray')
                    ->toggleable(),

                TextColumn::make('time_impact_flag')
                    ->label('Time')
                    ->badge()
                    ->formatStateUsing(fn (string $state, Rfi $record): string => $state === Rfi::IMPACT_NONE
                        ? '—'
                        : (Rfi::IMPACTS[$state] ?? $state)
                            .($record->time_impact_days !== null ? " ({$record->time_impact_days} d)" : ''))
                    ->color(fn (string $state): string => $state === Rfi::IMPACT_YES ? 'danger' : 'gray')
                    ->toggleable(),

                /*
                 * The exposure. Only shown for RFIs that state a time impact, because for everything else the question
                 * does not arise — and a column of dashes trains people not to look at it.
                 */
                TextColumn::make('delay_event_id')
                    ->label('Notified')
                    ->badge()
                    ->getStateUsing(fn (Rfi $record): ?string => match (true) {
                        $record->delay_event_id !== null => $record->delayEvent?->reference,
                        $record->timeImpactUnnotified() => 'no notice',
                        default => null,
                    })
                    ->placeholder('—')
                    ->color(fn (Rfi $record): string => $record->timeImpactUnnotified() ? 'danger' : 'gray')
                    ->tooltip('A stated time impact with no delay event behind it. The notice period is running from the day the answer was needed.'),
            ])
            ->filters([
                SelectFilter::make('job_id')
                    ->label('Job')
                    ->relationship('job', 'code')
                    ->searchable(),

                SelectFilter::make('ball_in_court')
                    ->label('Ball in court')
                    ->options(Rfi::COURTS),

                SelectFilter::make('status')->options(Rfi::STATUSES),

                Filter::make('outstanding')
                    ->label('Awaiting an answer')
                    ->query(fn (Builder $query): Builder => $query->awaitingAnswer())
                    ->toggle(),

                Filter::make('overdue')
                    ->label('Overdue')
                    ->query(fn (Builder $query): Builder => $query->overdue())
                    ->toggle(),

                Filter::make('unnotified')
                    ->label('Time impact, no notice')
                    ->query(fn (Builder $query): Builder => $query->unnotifiedTimeImpact())
                    ->toggle(),
            ])
            ->defaultSort('rfi_number', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Rfi $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('answer')
                    ->label('Record answer')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('info')
                    ->modalHeading('Record the answer')
                    ->modalDescription('Date it the day the answer was given, not the day you are typing it. The response time this register reports belongs to the other side, and dating it from the transcription flatters them.')
                    ->schema([
                        Textarea::make('answer')->label('The answer')->rows(4)->required(),
                        DatePicker::make('answered_on')->label('Given on')->native(false)->default(now())->required(),
                        Select::make('answered_by_contact_id')
                            ->label('By whom')
                            ->relationship('answeredByContact', 'name')
                            ->searchable()
                            ->visible(fn (): bool => modules()->enabled('invoicing')),
                    ])
                    ->visible(fn (Rfi $record): bool => auth()->user()?->can('answer', $record) ?? false)
                    ->action(fn (Rfi $record, array $data) => static::run(
                        fn () => app(RfiService::class)->answer($record, $data),
                        'Answer recorded.',
                        'The response time is measured from the day it was given.',
                    )),

                Action::make('close')
                    ->label('Close')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Closing takes it off the outstanding list. An RFI with no answer against it cannot be closed — cancel it with a reason instead.')
                    ->visible(fn (Rfi $record): bool => (auth()->user()?->can('close', $record) ?? false)
                        && $record->isAnswered())
                    ->action(fn (Rfi $record) => static::run(
                        fn () => app(RfiService::class)->close($record),
                        'Closed.',
                        'It stays in the register with its number.',
                    )),

                /*
                 * **The action this register earns its place with.**
                 *
                 * Gated on the delay register's own permission, not this one: raising a notice is a contractual act, and
                 * whoever may ask a question is not necessarily whoever may serve notice on the employer.
                 */
                Action::make('raiseDelayEvent')
                    ->label('Raise delay event')
                    ->icon('heroicon-o-clock')
                    ->color('danger')
                    ->modalHeading('Raise a delay event for this RFI')
                    ->modalDescription('Dated the day the answer was needed, not today — the notice period runs from when the work was blocked, and dating it now is how a claim is time-barred by its own paperwork.')
                    ->schema(fn (Rfi $record): array => [
                        TextInput::make('title')
                            ->default("Late information: {$record->subject}")
                            ->required()
                            ->maxLength(255),
                        TextInput::make('claimed_days')
                            ->label('Days claimed')
                            ->numeric()
                            ->default($record->time_impact_days)
                            ->helperText('Optional. §13\'s clock exists so events are raised early, before anybody knows what the delay is worth.'),
                    ])
                    ->visible(fn (Rfi $record): bool => auth()->user()?->can('raiseDelay', $record) ?? false)
                    ->action(fn (Rfi $record, array $data) => static::run(
                        fn () => app(RfiService::class)->raiseDelay($record, array_filter(
                            $data,
                            fn ($value): bool => $value !== null && $value !== '',
                        )),
                        'Delay event raised.',
                        'Dated the day the answer was needed. The notice clock is now on the delay register.',
                    )),

                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->modalHeading('Cancel this RFI')
                    ->modalDescription('The number stays in the register — it is numbered without gaps because both sides quote it, and a missing number is indistinguishable from a removal somebody wanted. The reason is what the register shows instead.')
                    ->schema([
                        Textarea::make('reason')->label('Why it no longer needs answering')->rows(2)->required(),
                    ])
                    ->visible(fn (Rfi $record): bool => (auth()->user()?->can('update', $record) ?? false)
                        && ! $record->isClosed())
                    ->action(fn (Rfi $record, array $data) => static::run(
                        fn () => app(RfiService::class)->cancel($record, $data['reason']),
                        'Cancelled.',
                        'The number stays used and the reason is on the row.',
                    )),
            ])
            ->emptyStateHeading('No RFIs')
            ->emptyStateDescription('A question asked by telephone and answered by telephone is a question neither side can prove was asked.');
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
