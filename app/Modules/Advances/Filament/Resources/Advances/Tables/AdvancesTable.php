<?php

namespace App\Modules\Advances\Filament\Resources\Advances\Tables;

use App\Filament\Support\PendingFirst;
use App\Modules\Advances\Models\Advance;
use App\Modules\Advances\Services\AdvanceApplications;
use App\Modules\Advances\Services\AdvanceService;
use App\Support\CompanyCurrency;
use App\Support\LandlordUserColumn;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
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
                    ->label(__('Employee'))
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('total_amount')->label(__('Advance'))->money(CompanyCurrency::code())->sortable(),
                TextColumn::make('monthly_instalment')->label(__('Per month'))->money(CompanyCurrency::code()),

                // Derived from the recovery ledger, so they cannot disagree with
                // what payroll actually took.
                TextColumn::make('recovered')
                    ->label(__('Recovered'))
                    ->state(fn (Advance $record): string => number_format($record->recoveredAmount(), 2))
                    ->alignEnd(),

                TextColumn::make('remaining')
                    ->label(__('Remaining'))
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

                TextColumn::make('started_on')->label(__('Given on'))->date('d M Y')->sortable()->toggleable(),
                TextColumn::make('reference')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(PendingFirst::sort(Advance::class, 'started_on'))
            ->filters([
                SelectFilter::make('status')->options([
                    Advance::STATUS_ACTIVE => 'Active',
                    Advance::STATUS_SETTLED => 'Settled',
                    Advance::STATUS_CANCELLED => 'Cancelled',
                ]),
            ])
            ->recordActions([
                // Money handed back outside payroll — cash, or a correction.
                Action::make('approveAdvance')
                    ->label(__('Approve'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Advance $record): bool => $record->status === Advance::STATUS_REQUESTED && (auth()->user()?->can('AdvanceUpdate') ?? false))
                    ->action(function (Advance $record): void {
                        try {
                            app(AdvanceApplications::class)->approve($record, auth()->user());
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Approved; recovery starts with the next payslip.')->send();
                    }),
                Action::make('refuseAdvance')
                    ->label(__('Refuse'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Advance $record): bool => $record->status === Advance::STATUS_REQUESTED && (auth()->user()?->can('AdvanceUpdate') ?? false))
                    ->schema([Textarea::make('reason')->required()->rows(2)])
                    ->action(function (Advance $record, array $data): void {
                        app(AdvanceApplications::class)->refuse($record, auth()->user(), $data['reason']);
                        Notification::make()->warning()->title('Refused, with your reason sent to the employee.')->send();
                    }),
                Action::make('pauseAdvance')
                    ->label(__('Pause'))
                    ->icon('heroicon-o-pause-circle')
                    ->color('gray')
                    ->visible(fn (Advance $record): bool => $record->status === Advance::STATUS_ACTIVE && (auth()->user()?->can('AdvanceUpdate') ?? false))
                    ->schema([Textarea::make('reason')->rows(2)->placeholder('Unpaid leave; hardship')])
                    ->action(function (Advance $record, array $data): void {
                        app(AdvanceApplications::class)->pause($record, (string) ($data['reason'] ?? ''));
                        Notification::make()->success()->title('Paused. Nothing is deducted until it is resumed.')->send();
                    }),
                Action::make('resumeAdvance')
                    ->label(__('Resume'))
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->visible(fn (Advance $record): bool => $record->status === Advance::STATUS_PAUSED && (auth()->user()?->can('AdvanceUpdate') ?? false))
                    ->action(function (Advance $record): void {
                        app(AdvanceApplications::class)->resume($record);
                        Notification::make()->success()->title('Resumed.')->send();
                    }),
                Action::make('recordRecovery')
                    ->label(__('Record repayment'))
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
