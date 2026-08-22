<?php

namespace App\Modules\ConstructionField\Filament\Resources\Activities\Tables;

use App\Modules\ConstructionField\Models\ProgrammeActivity;
use App\Modules\ConstructionField\Services\ProgrammeService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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
 * The programme as a register.
 *
 * **Three columns carry it, and none of them is a bar chart.**
 *
 * *Late* is days against the **accepted** programme, which is what entitlement is measured against — not against the
 * current plan, which moves every month and would report zero on a job that had re-programmed around its own delays.
 *
 * *Exposure* is the figure §13 is worth its length for: days a priced contract milestone is late with no extension of
 * time awarded against it. Damages accrue against that remainder and nothing else in the application is watching it.
 *
 * *Float* prints its **source** beside it, because §13's argument is that criticality and float are somebody else's
 * numbers. A job whose critical activities are all `manual` is a job where somebody typed a critical path, and that is
 * worth seeing before it reaches a claim.
 */
class ActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Id')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('name')
                    ->wrap()
                    ->limit(50)
                    ->searchable()
                    ->description(fn (ProgrammeActivity $record): ?string => $record->job?->code),

                IconColumn::make('is_contract_milestone')
                    ->label('Milestone')
                    ->boolean()
                    ->tooltip('A date the contract names. Extension of time moves it.'),

                TextColumn::make('baseline_finish')
                    ->label('Baseline')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('planned_finish')
                    ->label('Planned')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('actual_finish')
                    ->label('Actual')
                    ->date('d M Y')
                    // Named rather than blank: an activity with a baseline in the past and no actual is the finding.
                    ->placeholder(fn (ProgrammeActivity $record): string => $record->hasStarted() ? 'in progress' : 'not started')
                    ->sortable(),

                TextColumn::make('percent_complete')
                    ->label('%')
                    ->alignEnd()
                    ->sortable()
                    ->description(fn (ProgrammeActivity $record): ?string => $record->data_date
                        ? 'as at '.$record->data_date->format('d M')
                        // A percentage with no data date cannot be compared with last month's.
                        : 'no data date')
                    ->color(fn (ProgrammeActivity $record): string => $record->isBehindBaseline() ? 'warning' : 'gray'),

                /*
                 * Against the **accepted** programme. Against the current plan would report zero on a job that had
                 * re-programmed around its own delays, which is the number a claim cannot be built from.
                 */
                TextColumn::make('late')
                    ->label('Late')
                    ->badge()
                    ->getStateUsing(function (ProgrammeActivity $record): ?string {
                        $days = $record->daysLateAgainstBaseline();

                        return $days > 0 ? "{$days} d" : null;
                    })
                    ->placeholder('—')
                    ->color('warning')
                    ->tooltip('Days late against the accepted programme — what entitlement is measured against.'),

                /*
                 * **Liquidated damages accruing.** Lateness the contract has not excused, on a milestone it prices.
                 */
                TextColumn::make('exposure')
                    ->label('Exposure')
                    ->badge()
                    ->getStateUsing(function (ProgrammeActivity $record): ?string {
                        $days = $record->unexcusedLateDays();

                        return $days > 0 ? "{$days} d" : null;
                    })
                    ->placeholder('—')
                    ->color('danger')
                    ->tooltip('Days late with no extension of time awarded, on a milestone that carries liquidated damages.'),

                TextColumn::make('total_float_days')
                    ->label('Float')
                    ->alignEnd()
                    ->placeholder('—')
                    // The source, always beside it: these are somebody else's numbers.
                    ->description(fn (ProgrammeActivity $record): string => $record->sourceLabel())
                    ->color(fn (ProgrammeActivity $record): string => $record->is_critical ? 'danger' : 'gray')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('job_id')->label('Job')->relationship('job', 'code')->searchable(),

                SelectFilter::make('source')->options(ProgrammeActivity::SOURCES),

                SelectFilter::make('activity_type')->options(ProgrammeActivity::TYPES),

                Filter::make('milestones')
                    ->label('Contract milestones')
                    ->query(fn (Builder $query): Builder => $query->milestones())
                    ->toggle(),

                Filter::make('critical')
                    ->label('On the critical path (imported)')
                    ->query(fn (Builder $query): Builder => $query->where('is_critical', true))
                    ->toggle(),

                Filter::make('incomplete')
                    ->label('Not finished')
                    ->query(fn (Builder $query): Builder => $query->incomplete())
                    ->toggle(),

                Filter::make('late_to_start')
                    ->label('Should have started')
                    ->query(fn (Builder $query): Builder => $query->notStarted()
                        ->whereNotNull('planned_start')
                        ->whereDate('planned_start', '<', now()->toDateString()))
                    ->toggle(),
            ])
            ->defaultSort('baseline_finish')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (ProgrammeActivity $record): bool => auth()->user()?->can('update', $record) ?? false),

                /*
                 * **Progress is its own permission.**
                 *
                 * Percent complete and actual dates are what §14's earned value is computed from, and the person who
                 * reports 80% is not usually the person who owns the consequence of it being 60%.
                 */
                Action::make('progress')
                    ->label('Record progress')
                    ->icon('heroicon-o-chart-bar')
                    ->color('info')
                    ->modalHeading('Record progress')
                    ->modalDescription('Percent complete and actual dates are what every schedule index is computed from. 100% needs an actual finish and an actual finish needs 100% — a row saying the work is both finished and unfinished makes both useless.')
                    ->schema(fn (ProgrammeActivity $record): array => [
                        DatePicker::make('actual_start')->native(false)->default($record->actual_start),
                        DatePicker::make('actual_finish')->native(false)->default($record->actual_finish),
                        TextInput::make('percent_complete')->numeric()->default($record->percent_complete)->required(),
                        DatePicker::make('data_date')
                            ->label('Progress as at')
                            ->native(false)
                            ->default($record->data_date ?? now())
                            ->required(),
                    ])
                    ->visible(fn (ProgrammeActivity $record): bool => auth()->user()?->can('progress', $record) ?? false)
                    ->action(fn (ProgrammeActivity $record, array $data) => static::run(
                        fn () => app(ProgrammeService::class)->progress($record, $data),
                        'Progress recorded.',
                        'Measured as at the data date, so it can be compared with last month.',
                    )),

                DeleteAction::make()
                    // Never on an imported activity: deleting one puts this copy out of step with the file it came
                    // from, and the next import silently puts it back.
                    ->visible(fn (ProgrammeActivity $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->emptyStateHeading('No programme')
            ->emptyStateDescription('Store the programme; the scheduling stays in P6. What is needed here is a milestone with a contractual date, planned against actual, somewhere to hang a delay event, and an id an RFI can point at.');
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
