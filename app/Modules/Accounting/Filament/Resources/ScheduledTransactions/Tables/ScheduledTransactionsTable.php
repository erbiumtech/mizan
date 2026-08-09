<?php

namespace App\Modules\Accounting\Filament\Resources\ScheduledTransactions\Tables;

use App\Modules\Accounting\Models\ScheduledTransaction;
use App\Modules\Accounting\Services\ScheduledTransactionService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ScheduledTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('interval_months')
                    ->label('Every')
                    ->formatStateUsing(fn (ScheduledTransaction $record): string => $record->intervalLabel())
                    ->badge()
                    ->color('gray'),

                TextColumn::make('day_of_month')
                    ->label('On day')
                    ->alignCenter(),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->state(fn (ScheduledTransaction $record): float => $record->totalDebits())
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('next_due')
                    ->label('Next due')
                    ->state(function (ScheduledTransaction $record): string {
                        if (! $record->is_active) {
                            return 'paused';
                        }

                        // A year ahead is far enough to name the next one for any
                        // interval we offer, and bounded enough not to walk an
                        // open-ended schedule to the end of time.
                        $ahead = $record->occurrencesUpTo(CarbonImmutable::now()->addYear());
                        $future = collect($ahead)->first(fn ($date): bool => $date->greaterThanOrEqualTo(CarbonImmutable::now()->startOfDay()));

                        return $future?->format('d M Y') ?? 'finished';
                    })
                    ->color(fn (string $state): string => in_array($state, ['paused', 'finished'], true) ? 'gray' : 'primary'),

                TextColumn::make('outstanding')
                    ->label('Waiting')
                    ->state(fn (ScheduledTransaction $record): int => $record->is_active
                        ? count(app(ScheduledTransactionService::class)->outstandingFor($record))
                        : 0)
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->tooltip('Due dates that have no entry yet. They are raised on the nightly run.'),

                IconColumn::make('balanced')
                    ->label('Balances')
                    ->state(fn (ScheduledTransaction $record): bool => $record->isBalanced())
                    ->boolean()
                    ->tooltip(fn (ScheduledTransaction $record): ?string => $record->isBalanced()
                        ? null
                        : 'Debits and credits differ, so nothing is being raised from this schedule.'),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('ends_on')
                    ->label('Stops after')
                    ->date('d M Y')
                    ->placeholder('open-ended')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                Action::make('runNow')
                    ->label('Raise now')
                    ->icon('heroicon-o-play')
                    ->color('gray')
                    ->authorize('runNow')
                    ->visible(fn (ScheduledTransaction $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading('Raise the outstanding entries now')
                    ->modalDescription('They are raised as drafts, exactly as the nightly run would. Nothing reaches the ledger until somebody approves and posts them.')
                    ->action(function (ScheduledTransaction $record): void {
                        $service = app(ScheduledTransactionService::class);
                        $dates = $service->outstandingFor($record);

                        if ($dates === []) {
                            Notification::make()
                                ->title('Nothing outstanding')
                                ->body('Every due date up to today already has an entry.')
                                ->info()
                                ->send();

                            return;
                        }

                        $raised = collect($dates)
                            ->map(fn ($date) => $service->raise($record, $date))
                            ->filter();

                        if ($raised->isEmpty()) {
                            Notification::make()
                                ->title('Nothing could be raised')
                                ->body($record->isBalanced()
                                    ? 'The dates fall in a closed year, or an account no longer accepts entries.'
                                    : 'Debits and credits do not balance. Fix the lines and try again.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title($raised->count().' draft entry(ies) raised')
                            ->body('Find them under Journal Entries, still as drafts.')
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}
