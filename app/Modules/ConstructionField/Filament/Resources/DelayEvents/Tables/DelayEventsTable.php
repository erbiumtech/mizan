<?php

namespace App\Modules\ConstructionField\Filament\Resources\DelayEvents\Tables;

use App\Modules\ConstructionField\Models\DelayEvent;
use App\Modules\ConstructionField\Services\DelayEventService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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
 * The delay-event register, built around one column.
 *
 * **Days left is the reason this screen exists.** §13: "a valid claim lost to a missed notice is the single most common
 * way a contractor donates money, and it fails in absolute silence." So the register leads with the clock, badges an
 * expired one in red, and offers *Awaiting notice* and *Time-barred* as toggles — the second is the list of money the
 * company has already lost and does not know about, which is the same shape as §12's un-notified back-charges.
 *
 * **Nothing here determines itself.** Serving notice, submitting particulars and determining are three actions taken by
 * two different people, and the determination is the one with its own permission.
 */
class DelayEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Ref')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('job.code')
                    ->label('Job')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('title')
                    ->wrap()
                    ->limit(60)
                    ->description(fn (DelayEvent $record): string => $record->causeLabel())
                    ->searchable(),

                TextColumn::make('occurred_on')
                    ->label('Occurred')
                    ->date()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('notice_required_by')
                    ->label('Notice due')
                    ->date()
                    ->sortable(),

                /*
                 * **The column the screen is for.** A date alone makes the reader do the arithmetic, and the arithmetic
                 * is the whole point — "in 3 days" is actionable where "14 Sep" is a fact.
                 */
                TextColumn::make('clock')
                    ->label('Clock')
                    ->badge()
                    ->getStateUsing(function (DelayEvent $record): string {
                        if ($record->noticeGiven()) {
                            return $record->noticeWasLate() ? 'Served late' : 'Served';
                        }

                        if ($record->isClosed()) {
                            return ucfirst($record->status);
                        }

                        $days = $record->daysUntilNoticeDue();

                        return match (true) {
                            $days < 0 => 'TIME-BARRED: '.abs($days).'d over',
                            $days === 0 => 'Due today',
                            default => $days.'d left',
                        };
                    })
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, 'TIME-BARRED') => 'danger',
                        $state === 'Served late' => 'warning',
                        $state === 'Served' => 'success',
                        $state === 'Due today' => 'danger',
                        // Grey for a closed event: the clock stopped mattering, which is different from being safe.
                        in_array($state, ['Withdrawn', 'Rejected', 'Determined'], true) => 'gray',
                        default => 'warning',
                    })
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->orderBy('notice_required_by', $direction)),

                TextColumn::make('claimed_days')
                    ->label('Claimed')
                    ->alignEnd()
                    // Not a dash: nothing claimed yet is the ordinary state early on, and it is what the notice
                    // protects — the whole entitlement rather than a figure.
                    ->placeholder('not yet assessed')
                    ->toggleable(),

                TextColumn::make('awarded_days')
                    ->label('Awarded')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('job_id')
                    ->label('Job')
                    ->relationship('job', 'code')
                    ->searchable(),

                SelectFilter::make('cause_category')
                    ->label('Cause')
                    ->options(DelayEvent::CAUSES),

                SelectFilter::make('status')
                    ->options(collect([
                        DelayEvent::STATUS_OPEN, DelayEvent::STATUS_NOTIFIED,
                        DelayEvent::STATUS_PARTICULARS_SUBMITTED, DelayEvent::STATUS_UNDER_ASSESSMENT,
                        DelayEvent::STATUS_DETERMINED, DelayEvent::STATUS_REJECTED, DelayEvent::STATUS_WITHDRAWN,
                    ])->mapWithKeys(fn (string $s): array => [$s => ucfirst(str_replace('_', ' ', $s))])->all()),

                Filter::make('awaiting_notice')
                    ->label('Awaiting notice')
                    ->query(fn (Builder $query) => $query->awaitingNotice())
                    ->toggle(),

                /*
                 * The exposure list: money already lost. A query rather than a habit, which is the shape §12's
                 * un-notified back-charge filter settled.
                 */
                Filter::make('time_barred')
                    ->label('Time-barred — notice missed')
                    ->query(fn (Builder $query) => $query->awaitingNotice()
                        ->whereDate('notice_required_by', '<', now()->toDateString()))
                    ->toggle(),
            ])
            ->defaultSort('notice_required_by')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (DelayEvent $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('giveNotice')
                    ->label('Record notice')
                    ->icon('heroicon-o-megaphone')
                    ->color('success')
                    ->modalHeading('Record the notice served')
                    ->modalDescription('The date the notice went to the Engineer, not the date you are typing. A late notice is recorded as late rather than refused — whether lateness bars the claim is a contractual argument, and no notice at all never survives one.')
                    ->schema([
                        DatePicker::make('notice_given_on')
                            ->label('Served on')
                            ->native(false)
                            ->default(now())
                            ->required(),
                    ])
                    ->visible(fn (DelayEvent $record): bool => auth()->user()?->can('notify', $record) ?? false)
                    ->action(fn (DelayEvent $record, array $data) => static::run(
                        fn () => app(DelayEventService::class)->giveNotice($record, $data['notice_given_on']),
                        'Notice recorded.',
                        'The clock has stopped, and the particulars are now due on their own date.',
                    )),

                Action::make('particulars')
                    ->label('Submit particulars')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->modalHeading('Record the particulars submitted')
                    ->schema([
                        DatePicker::make('particulars_submitted_on')
                            ->label('Submitted on')->native(false)->default(now())->required(),
                        TextInput::make('claimed_days')->label('Days claimed')->numeric(),
                        TextInput::make('cost_claimed')->label('Cost claimed')->numeric(),
                    ])
                    ->visible(fn (DelayEvent $record): bool => auth()->user()?->can('submitParticulars', $record) ?? false)
                    ->action(fn (DelayEvent $record, array $data) => static::run(
                        fn () => app(DelayEventService::class)->submitParticulars(
                            $record,
                            isset($data['claimed_days']) ? (float) $data['claimed_days'] : null,
                            isset($data['cost_claimed']) ? (float) $data['cost_claimed'] : null,
                            $data['particulars_submitted_on'],
                        ),
                        'Particulars recorded.',
                        'The claim is now with whoever determines it.',
                    )),

                Action::make('determine')
                    ->label('Determine')
                    ->icon('heroicon-o-scale')
                    ->color('warning')
                    ->modalHeading('Award time, money, or neither')
                    ->modalDescription('This moves the completion date and decides whether liquidated damages can be levied. Awarding nothing is a rejection and is recorded as one — a determined event showing zero days reads as an oversight to whoever finds it next year.')
                    ->schema([
                        TextInput::make('awarded_days')
                            ->label('Days awarded')
                            ->numeric()
                            ->helperText('More than was claimed is refused: that would be a different event, needing its own notice.'),
                        TextInput::make('cost_awarded')->label('Cost awarded')->numeric(),
                        Textarea::make('reason')
                            ->label('Grounds')
                            ->rows(3)
                            ->required()
                            ->helperText('The other party will read this. "0 days" with no grounds is the sentence that goes to adjudication.'),
                    ])
                    ->visible(fn (DelayEvent $record): bool => auth()->user()?->can('determine', $record) ?? false)
                    ->action(fn (DelayEvent $record, array $data) => static::run(
                        fn () => app(DelayEventService::class)->determine(
                            $record,
                            isset($data['awarded_days']) ? (float) $data['awarded_days'] : null,
                            isset($data['cost_awarded']) ? (float) $data['cost_awarded'] : null,
                            $data['reason'],
                            $data['determined_on'] ?? null,
                        ),
                        'Determined.',
                        'The award and the grounds are on the record.',
                    )),

                Action::make('withdraw')
                    ->label('Withdraw')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->modalHeading('Withdraw this event')
                    ->modalDescription('The row and its reference stay: a gap in the series is a question at adjudication, and "withdrawn on the 14th" is an answer.')
                    ->schema([Textarea::make('reason')->label('Why')->rows(2)->required()])
                    ->visible(fn (DelayEvent $record): bool => auth()->user()?->can('withdraw', $record) ?? false)
                    ->action(fn (DelayEvent $record, array $data) => static::run(
                        fn () => app(DelayEventService::class)->withdraw($record, $data['reason']),
                        'Withdrawn.',
                        'The reason is on the record.',
                    )),
            ])
            ->emptyStateHeading('No delay events')
            ->emptyStateDescription('Raise one as soon as something delays the work, before anybody knows what it is worth. The notice period runs from the day it happened, and a claim lost to a missed notice fails without any warning at all.');
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
