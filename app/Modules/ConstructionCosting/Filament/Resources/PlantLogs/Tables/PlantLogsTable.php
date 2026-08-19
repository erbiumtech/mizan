<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\PlantLogs\Tables;

use App\Modules\ConstructionCosting\Models\PlantItem;
use App\Modules\ConstructionCosting\Models\PlantLog;
use App\Modules\ConstructionCosting\Services\PlantService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The plant log register.
 *
 * **The *Books cost* column exists because two rows that look identical behave differently.** An owned machine's
 * approved log charges the job internal hire; a hired machine's charges nothing, because its supplier invoice is
 * already the cost. Without the column, "why does this log show no cost" is a question somebody answers by reading
 * source code — and the wrong answer to it is a job charged twice for one excavator.
 */
class PlantLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('logged_on')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('plantItem.code')
                    ->label('Machine')
                    ->description(fn (PlantLog $record): ?string => $record->plantItem?->name)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('job.code')
                    ->label('Job')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('costCode.code')
                    ->label('Code')
                    ->toggleable(),

                TextColumn::make('working_units')
                    ->label('Working')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('idle_units')
                    ->label('Idle')
                    ->alignEnd()
                    ->description(fn (PlantLog $record): ?string => $record->downtime_reason)
                    ->toggleable(),

                TextColumn::make('standby_units')
                    ->label('Standby')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('meter_movement')
                    ->label('Meter')
                    ->alignEnd()
                    ->getStateUsing(fn (PlantLog $record): ?float => $record->meterMovement())
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('charge_amount')
                    ->label('Charge')
                    ->money('PKR')
                    ->alignEnd()
                    ->placeholder('at approval')
                    ->summarize(Sum::make()->money('PKR')->label('Charged')),

                TextColumn::make('books_cost')
                    ->label('Books cost')
                    ->badge()
                    ->getStateUsing(fn (PlantLog $record): string => $record->plantItem?->isOwned()
                        ? 'Internal hire'
                        : 'No — invoice does')
                    ->color(fn (string $state): string => $state === 'Internal hire' ? 'success' : 'gray')
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PlantLog::STATUS_APPROVED => 'success',
                        PlantLog::STATUS_REVERSED => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('plant_item_id')
                    ->label('Machine')
                    ->relationship('plantItem', 'name')
                    ->searchable(),

                SelectFilter::make('job_id')
                    ->label('Job')
                    ->relationship('job', 'code')
                    ->searchable(),

                SelectFilter::make('status')
                    ->options([
                        PlantLog::STATUS_DRAFT => 'Draft',
                        PlantLog::STATUS_APPROVED => 'Approved',
                        PlantLog::STATUS_REVERSED => 'Reversed',
                    ]),

                Filter::make('awaiting_approval')
                    ->label('Awaiting approval')
                    ->query(fn (Builder $query) => $query->draft())
                    ->toggle(),

                // Standing plant, which is often somebody else's cost and almost never recovered because nobody
                // totals it.
                Filter::make('standing')
                    ->label('Stood idle or on standby')
                    ->query(fn (Builder $query) => $query
                        ->where(fn (Builder $q) => $q->where('idle_units', '>', 0)->orWhere('standby_units', '>', 0)))
                    ->toggle(),
            ])
            ->defaultSort('logged_on', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (PlantLog $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Price this log')
                    ->modalDescription(fn (PlantLog $record): string => $record->plantItem?->ownership === PlantItem::OWNERSHIP_OWNED
                        ? 'The machine\'s rates are frozen onto this log and the job is charged internal hire, which the recovery account will be credited with.'
                        : 'The machine\'s rates are frozen onto this log. No cost is booked — the supplier\'s invoice is the cost, and this figure is what that invoice gets checked against.')
                    ->visible(fn (PlantLog $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->action(fn (PlantLog $record) => static::run(
                        fn () => app(PlantService::class)->approve($record),
                        'Approved.',
                        'The log is priced at the machine\'s current rates, which are now frozen onto it.',
                    )),

                Action::make('reverse')
                    ->label('Reverse')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->modalHeading('Take this log back')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->rows(2)
                            ->required(),
                    ])
                    ->visible(fn (PlantLog $record): bool => auth()->user()?->can('reverse', $record) ?? false)
                    ->action(fn (PlantLog $record, array $data) => static::run(
                        fn () => app(PlantService::class)->reverse($record, $data['reason']),
                        'Reversed.',
                        'Any cost it booked has been backed out, and the reason is on the log.',
                    )),

                DeleteAction::make()
                    ->visible(fn (PlantLog $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->emptyStateHeading('No plant logs')
            ->emptyStateDescription('One machine, one day. For owned plant this is what charges the job; for hired plant it is what checks the supplier\'s invoice.');
    }

    /** Service refusals are sentences somebody needs to read, so they are surfaced rather than thrown. */
    private static function run(callable $call, string $title, string $body): void
    {
        try {
            $call();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($title)->body($body)->send();
    }
}
