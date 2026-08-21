<?php

namespace App\Modules\ConstructionField\Filament\Resources\DailyLogs\Tables;

use App\Modules\ConstructionField\Models\DailyLog;
use App\Modules\ConstructionField\Services\DailyLogService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The diary register.
 *
 * **Three columns carry it, and none of them is the weather prose.** Man-hours, because it is §17's exposure denominator
 * and dayworks' starting point; standing plant, because idle against working is what a standing-time claim is made of;
 * and *unnotified*, which is the count of diary events that cost time and that nobody has served notice for — the silent
 * loss §13's clock exists to prevent, asked of the diary rather than of somebody's memory.
 *
 * The **Locked** badge is the other half of §16.1: an approved day is evidence, and a register that did not show which
 * days were signed off would make somebody open each one to find out.
 */
class DailyLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('log_date')
                    ->label('Date')
                    ->date('D d M Y')
                    ->sortable(),

                TextColumn::make('job.code')
                    ->label('Job')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('working_conditions')
                    ->label('Conditions')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        DailyLog::CONDITION_STOPPED => 'danger',
                        DailyLog::CONDITION_PARTIALLY_DISRUPTED => 'warning',
                        default => 'success',
                    })
                    ->formatStateUsing(fn (string $state): string => DailyLog::CONDITIONS[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('weather_hours_lost')
                    ->label('Weather hrs')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),

                // §17's denominator. No summariser: it is a fold over the children, and `Sum` needs a real column —
                // the mistake Phase 6c and 8b both made, recorded in the plan.
                TextColumn::make('man_hours')
                    ->label('Man-hours')
                    ->alignEnd()
                    ->getStateUsing(fn (DailyLog $record): float => $record->totalManHours())
                    ->description(fn (DailyLog $record): ?string => $record->totalHeadcount() > 0
                        ? $record->totalHeadcount().' on site'
                        : null),

                TextColumn::make('standing_plant')
                    ->label('Plant standing')
                    ->alignEnd()
                    ->getStateUsing(fn (DailyLog $record): float => $record->standingPlantHours())
                    ->toggleable(),

                // Counted in the query rather than folded over a loaded relation: a register page of thirty days would
                // otherwise load every docket and every photograph to print two numbers.
                TextColumn::make('deliveries_count')
                    ->label('Deliveries')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('photos_count')
                    ->label('Photos')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                /*
                 * The exposure count. Not a dash when zero — a diary with nothing unnotified is the state somebody
                 * wants confirmed, and a blank cell reads as "not checked".
                 */
                TextColumn::make('unnotified')
                    ->label('Unnotified')
                    ->badge()
                    ->getStateUsing(fn (DailyLog $record): string => (string) $record->unnotifiedEvents()->count())
                    ->color(fn (string $state): string => $state === '0' ? 'gray' : 'danger')
                    ->tooltip('Events that cost time, are not the contractor\'s own risk, and have no delay event behind them.'),

                TextColumn::make('approved_at')
                    ->label('Locked')
                    ->badge()
                    ->getStateUsing(fn (DailyLog $record): string => $record->isApproved()
                        ? 'Approved'
                        : ($record->wasReopened() ? 'Reopened' : 'Open'))
                    ->color(fn (string $state): string => match ($state) {
                        'Approved' => 'success',
                        'Reopened' => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (DailyLog $record): ?string => $record->reopen_reason),
            ])
            ->filters([
                SelectFilter::make('job_id')
                    ->label('Job')
                    ->relationship('job', 'code')
                    ->searchable(),

                SelectFilter::make('working_conditions')
                    ->label('Conditions')
                    ->options(DailyLog::CONDITIONS),

                Filter::make('open')
                    ->label('Not yet signed off')
                    ->query(fn (Builder $query) => $query->open())
                    ->toggle(),

                Filter::make('time_lost')
                    ->label('Lost time')
                    ->query(fn (Builder $query) => $query->where('weather_hours_lost', '>', 0)
                        ->orWhereNot('working_conditions', DailyLog::CONDITION_WORKABLE))
                    ->toggle(),
            ])
            ->defaultSort('log_date', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (DailyLog $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('approve')
                    ->label('Sign off')
                    ->icon('heroicon-o-lock-closed')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Sign off and lock this day')
                    ->modalDescription('The day and everything on it — manpower, plant, events — become read-only. That is what makes a diary evidence: one anybody can revise afterwards proves nothing about what happened. It can be reopened with a reason if it turns out to be wrong.')
                    ->visible(fn (DailyLog $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->action(fn (DailyLog $record) => static::run(
                        fn () => app(DailyLogService::class)->approve($record),
                        'Signed off.',
                        'The day is locked, with your name against it.',
                    )),

                Action::make('reopen')
                    ->label('Reopen')
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->modalHeading('Reopen a signed day')
                    ->modalDescription('The approval is cleared, so the day has to be signed off again afterwards rather than carrying an approval that predates the change. The reason stays on the record — a reopened diary is one somebody will ask about.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('What was wrong with it')
                            ->rows(2)
                            ->required(),
                    ])
                    ->visible(fn (DailyLog $record): bool => auth()->user()?->can('reopen', $record) ?? false)
                    ->action(fn (DailyLog $record, array $data) => static::run(
                        fn () => app(DailyLogService::class)->reopen($record, $data['reason']),
                        'Reopened.',
                        'The reason is on the record, and the day needs signing off again.',
                    )),
            ])
            ->emptyStateHeading('No diary yet')
            ->emptyStateDescription('One entry per job per day. The weather columns and the hours lost are what a weather claim is built from, and the events are what a delay notice is built from.');
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
