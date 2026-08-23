<?php

namespace App\Modules\ConstructionField\Filament\Resources\Submittals\Tables;

use App\Modules\ConstructionField\Models\Submittal;
use App\Modules\ConstructionField\Models\SubmittalReview;
use App\Modules\ConstructionField\Services\SubmittalService;
use App\Support\TenantDb;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The register as a schedule control.
 *
 * **Submit by** is the column the screen exists for: computed backwards from the date the item is needed on site, and
 * red once it has passed. §16.3's argument is that a typed one goes stale the day the programme moves — so this is
 * recomputed on every render, and it cannot be sorted in SQL because it is not a column.
 *
 * **Rounds** is the column §16.3 says a status cannot replace. One round was budgeted for. Two or three have spent float
 * nobody planned, and the badge turns amber to say so.
 *
 * **Overrun** is the only claimable part of a submittal being late: the contractor submits, so its own lateness is a
 * risk it owns, while a reviewer past their period has taken somebody else's programme.
 */
class SubmittalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('spec_section')
                    ->label('Section')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('title')
                    ->wrap()
                    ->limit(50)
                    ->searchable()
                    ->description(fn (Submittal $record): string => $record->typeLabel()
                        .($record->job?->code ? " · {$record->job->code}" : '')),

                TextColumn::make('responsible_label')
                    ->label('Who owes it')
                    ->getStateUsing(fn (Submittal $record): string => $record->responsibleName())
                    ->toggleable(),

                IconColumn::make('is_long_lead')
                    ->label('Long lead')
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('required_on_site_date')
                    ->label('On site')
                    ->date('d M Y')
                    ->placeholder('no date')
                    ->sortable(),

                /*
                 * **The computed date** — §16.3's whole point, and deliberately not sortable: it is not a column, and a
                 * sort that silently ordered by `required_on_site_date` instead would be a lie the interface told.
                 * `SubmittalService::dueToSubmit()` is the ordered report.
                 */
                TextColumn::make('submit_by')
                    ->label('Submit by')
                    ->badge()
                    ->getStateUsing(function (Submittal $record): string {
                        $by = $record->submitBy();

                        if ($by === null) {
                            return 'no programme date';
                        }

                        if ($record->submitted_on !== null) {
                            $late = $record->daysLate();

                            return $late > 0 ? "{$late} days late" : 'in time';
                        }

                        $days = (int) $record->daysUntilSubmitBy();

                        return $days < 0 ? abs($days).' days late' : "{$days} days left";
                    })
                    ->color(fn (Submittal $record): string => match (true) {
                        $record->submitBy() === null => 'gray',
                        $record->isLateToSubmit() => 'danger',
                        $record->submitted_on !== null && $record->daysLate() > 0 => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (Submittal $record): ?string => $record->submitBy()?->format('d M Y')),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Submittal::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Submittal::STATUS_APPROVED, Submittal::STATUS_CLOSED => 'success',
                        Submittal::STATUS_APPROVED_AS_NOTED => 'info',
                        Submittal::STATUS_REJECTED, Submittal::STATUS_REVISE_AND_RESUBMIT => 'danger',
                        Submittal::STATUS_UNDER_REVIEW => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                /*
                 * §16.3's schedule risk, as a number. Amber past the first round, because the review period was
                 * budgeted once and every round after it spends float nobody planned.
                 */
                TextColumn::make('rounds')
                    ->label('Rounds')
                    ->badge()
                    ->getStateUsing(fn (Submittal $record): string => (string) $record->roundsUsed())
                    ->color(fn (Submittal $record): string => $record->hasResubmitted() ? 'warning' : 'gray')
                    ->tooltip('A submittal that has been round three times is a schedule risk a status column cannot show.'),

                TextColumn::make('overrun')
                    ->label('Overrun')
                    ->badge()
                    ->getStateUsing(function (Submittal $record): ?string {
                        $days = $record->reviewOverrunDays();

                        return $days > 0 ? "{$days} d" : null;
                    })
                    ->placeholder('—')
                    ->color('danger')
                    ->tooltip('Days the reviewer kept it beyond the review period — the only claimable part of a submittal being late.'),
            ])
            ->filters([
                SelectFilter::make('job_id')->label('Job')->relationship('job', 'code')->searchable(),

                SelectFilter::make('type')->options(Submittal::TYPES),

                SelectFilter::make('status')->options(Submittal::STATUSES),

                Filter::make('outstanding')
                    ->label('Not yet cleared')
                    ->query(fn (Builder $query): Builder => $query->outstanding())
                    ->toggle(),

                Filter::make('long_lead')
                    ->label('Long lead')
                    ->query(fn (Builder $query): Builder => $query->longLead())
                    ->toggle(),

                Filter::make('resubmitted')
                    ->label('Been round more than once')
                    // `has()` rather than a HAVING on a `withCount` alias, which SQLite refuses as a non-aggregate.
                    ->query(fn (Builder $query): Builder => $query->has('reviews', '>', 1))
                    ->toggle(),
            ])
            ->defaultSort('required_on_site_date')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Submittal $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('submit')
                    ->label('Submit')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->modalHeading('Send it for review')
                    ->modalDescription('This opens a round. The review period is snapshotted onto it, so an overrun stays measured against the period that applied when it went out.')
                    ->schema(fn (Submittal $record): array => [
                        DatePicker::make('sent_on')->label('Sent on')->native(false)->default(now())->required(),
                        TextInput::make('revision')->default($record->revision)->maxLength(255),
                        TextInput::make('review_period_days')
                            ->label('Review period (days)')
                            ->numeric()
                            ->default($record->review_period_days)
                            ->required(),
                        // Options rather than `relationship()`: the action's record is the submittal, and the reviewer
                        // belongs to the round this is about to create.
                        Select::make('reviewer_contact_id')
                            ->label('Reviewer')
                            ->options(fn (): array => static::contacts())
                            ->searchable()
                            ->visible(fn (): bool => modules()->enabled('invoicing')),
                        TextInput::make('reviewer_label')->label('Reviewer, in words')->maxLength(255),
                    ])
                    ->visible(fn (Submittal $record): bool => (auth()->user()?->can('submit', $record) ?? false)
                        && $record->isAwaitingSubmission())
                    ->action(fn (Submittal $record, array $data) => static::run(
                        fn () => app(SubmittalService::class)->submit($record, array_filter(
                            $data,
                            fn ($value): bool => $value !== null && $value !== '',
                        )),
                        'Out for review.',
                        'The round is open and its review period is fixed.',
                    )),

                Action::make('recordReturn')
                    ->label('Record return')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('info')
                    ->modalHeading('Record the reviewer\'s return')
                    ->modalDescription('Date it the day it came back. The turnaround this register reports is the reviewer\'s, and dating it from when you filed it flatters them.')
                    ->schema([
                        Select::make('result')->options(SubmittalReview::RESULTS)->required(),
                        DatePicker::make('returned_on')->label('Came back on')->native(false)->default(now())->required(),
                        Textarea::make('comments')->rows(3),
                    ])
                    ->visible(fn (Submittal $record): bool => (auth()->user()?->can('recordReturn', $record) ?? false)
                        && $record->status === Submittal::STATUS_UNDER_REVIEW)
                    ->action(function (Submittal $record, array $data): void {
                        $open = $record->reviews()->whereNull('returned_on')->first();

                        if ($open === null) {
                            Notification::make()->danger()->title('There is no round out for review.')->send();

                            return;
                        }

                        static::run(
                            fn () => app(SubmittalService::class)->recordReturn($open, $data),
                            'Return recorded.',
                            'The register\'s status now follows the round.',
                        );
                    }),

                /*
                 * Notify the reviewer's overrun, dated the day their period expired.
                 *
                 * Gated on the delay register's grant rather than this one: serving notice on the employer is not the
                 * same act as filing paperwork.
                 */
                Action::make('raiseDelayEvent')
                    ->label('Notify late review')
                    ->icon('heroicon-o-clock')
                    ->color('danger')
                    ->modalHeading('Notify a late review')
                    ->modalDescription('Dated the day the review period expired, not the day the drawing came back. The notice period runs from the event, and the event is the reviewer passing their own deadline.')
                    ->visible(fn (Submittal $record): bool => (auth()->user()?->can('raiseDelay', $record) ?? false)
                        && $record->reviews->contains(fn (SubmittalReview $r): bool => $r->overrunUnnotified()))
                    ->requiresConfirmation()
                    ->action(function (Submittal $record): void {
                        $round = $record->reviews->first(fn (SubmittalReview $r): bool => $r->overrunUnnotified());

                        if ($round === null) {
                            Notification::make()->danger()->title('No round has overrun without a notice.')->send();

                            return;
                        }

                        static::run(
                            fn () => app(SubmittalService::class)->raiseDelayForOverrun($round),
                            'Delay event raised.',
                            'Dated the day the review period expired. The notice clock is now on the delay register.',
                        );
                    }),

                DeleteAction::make()
                    // Only while nothing has been submitted: a submittal with rounds against it is the schedule record
                    // §16.3 exists to keep.
                    ->visible(fn (Submittal $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->emptyStateHeading('No submittals')
            ->emptyStateDescription('The register\'s first act on a real job is to show how many submit-by dates have already passed, because nobody did the subtraction when the programme was agreed.');
    }

    /**
     * Contacts, read through the query builder.
     *
     * Invoicing owns them and this module does not require it, so the picker is hidden without the module and this
     * never names `Contact` from a table action — the same treatment §16.2's pickers get.
     *
     * @return array<int, string>
     */
    private static function contacts(): array
    {
        if (! modules()->enabled('invoicing')) {
            return [];
        }

        return TenantDb::table('contacts')
            ->orderBy('name')
            ->limit(500)
            ->pluck('name', 'id')
            ->all();
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
