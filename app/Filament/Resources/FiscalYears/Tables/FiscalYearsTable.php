<?php

namespace App\Filament\Resources\FiscalYears\Tables;

use App\Models\FiscalYear;
use App\Modules\Accounting\Services\FiscalYearClosingService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FiscalYearsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Year Name')
                    ->searchable(),

                IconColumn::make('is_active')
                    ->label('Is Active')
                    ->boolean(),

                TextColumn::make('closed_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (FiscalYear $record): string => $record->isClosed() ? 'Closed' : 'Open')
                    ->color(fn (FiscalYear $record): string => $record->isClosed() ? 'gray' : 'success')
                    ->description(fn (FiscalYear $record): ?string => $record->isClosed()
                        ? 'on '.$record->closed_at->format('d M Y')
                        : null),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('close')
                    ->label('Close')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->visible(fn (FiscalYear $record): bool => ! $record->isClosed()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalHeading('Close fiscal year')
                    // The blockers are listed up front: finding out why only after
                    // confirming a lock is a poor trade.
                    ->modalDescription(function (FiscalYear $record): string {
                        $blockers = app(FiscalYearClosingService::class)->blockers($record);

                        return $blockers === []
                            ? "Once closed, nothing may post into {$record->name}. You can reopen it later."
                            : "This year cannot be closed yet:\n\n• ".implode("\n\n• ", $blockers);
                    })
                    ->modalSubmitActionLabel('Close year')
                    ->action(function (FiscalYear $record): void {
                        try {
                            app(FiscalYearClosingService::class)->close($record, auth()->user());

                            Notification::make()
                                ->title("Fiscal year {$record->name} closed.")
                                ->success()
                                ->send();
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()
                                ->title('Cannot close this year')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                Action::make('reopen')
                    ->label('Reopen')
                    ->icon('heroicon-o-lock-open')
                    ->color('gray')
                    ->visible(fn (FiscalYear $record): bool => $record->isClosed()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(fn (FiscalYear $record): string => "Reopening {$record->name} allows entries to post into it again.")
                    ->action(function (FiscalYear $record): void {
                        app(FiscalYearClosingService::class)->reopen($record, auth()->user());

                        Notification::make()
                            ->title("Fiscal year {$record->name} reopened.")
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
