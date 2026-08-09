<?php

namespace App\Modules\Advances\Filament\Resources\Advances\Tables;

use App\Modules\Advances\Models\Advance;
use App\Modules\Advances\Services\AdvanceService;
use App\Support\LandlordUserColumn;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AdvancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')
                    ->label('Employee')
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('total_amount')->label('Advance')->money('PKR')->sortable(),
                TextColumn::make('monthly_instalment')->label('Per month')->money('PKR'),

                // Derived from the recovery ledger, so they cannot disagree with
                // what payroll actually took.
                TextColumn::make('recovered')
                    ->label('Recovered')
                    ->state(fn (Advance $record): string => number_format($record->recoveredAmount(), 2))
                    ->alignEnd(),

                TextColumn::make('remaining')
                    ->label('Remaining')
                    ->state(fn (Advance $record): string => number_format($record->remainingAmount(), 2))
                    ->weight('bold')
                    ->alignEnd(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Advance::STATUS_ACTIVE => 'warning',
                        Advance::STATUS_SETTLED => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('started_on')->label('Given on')->date('d M Y')->sortable()->toggleable(),
                TextColumn::make('reference')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('started_on', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    Advance::STATUS_ACTIVE => 'Active',
                    Advance::STATUS_SETTLED => 'Settled',
                    Advance::STATUS_CANCELLED => 'Cancelled',
                ]),
            ])
            ->recordActions([
                // Money handed back outside payroll — cash, or a correction.
                Action::make('recordRecovery')
                    ->label('Record repayment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Advance $record): bool => $record->remainingAmount() > 0
                        && (auth()->user()?->can('AdvanceUpdate') ?? false))
                    ->schema([
                        TextInput::make('amount')
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->helperText(fn (Advance $record): string => number_format($record->remainingAmount(), 2).' outstanding.'),
                        DatePicker::make('recovered_on')->native(false)->default(now())->required(),
                        TextInput::make('note')->maxLength(255),
                    ])
                    ->action(function (Advance $record, array $data): void {
                        try {
                            app(AdvanceService::class)->recordManualRecovery(
                                $record,
                                (float) $data['amount'],
                                $data['recovered_on'],
                                $data['note'] ?? null,
                            );
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Recorded. '.number_format($record->fresh()->remainingAmount(), 2).' still outstanding.')
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
