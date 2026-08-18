<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\Contracts\Tables;

use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Services\ContractService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * The contract register.
 *
 * The two columns worth explaining are **schedule** and **defects expiry**. The schedule total is the sum of
 * the priced lines, which is agreed to differ from the contract sum on a lump-sum contract by whatever was not
 * broken down — showing only one of the two would hide the gap. The expiry is computed from the completion
 * date and the defects period, and shows an em dash rather than a guess while either is missing, because the
 * second retention release hangs off it.
 */
class ContractsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contract_number')
                    ->label('Number')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('job.code')
                    ->label('Job')
                    ->description(fn (Contract $record): ?string => $record->job?->name)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('title')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('side')
                    ->badge()
                    ->color(fn (string $state): string => $state === Contract::SIDE_RECEIVABLE ? 'success' : 'warning')
                    ->formatStateUsing(fn (string $state): string => $state === Contract::SIDE_RECEIVABLE ? 'Receivable' : 'Payable'),

                TextColumn::make('contract_standard')
                    ->label('Family')
                    ->badge()
                    ->formatStateUsing(fn (Contract $record): string => $record->vocabulary()->word('standard')),

                TextColumn::make('measurement_basis')
                    ->label('Basis')
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state)))
                    ->toggleable(),

                TextColumn::make('contact.name')
                    ->label('Other party')
                    ->placeholder('Not awarded')
                    ->toggleable(),

                TextColumn::make('contract_sum')
                    ->label('Sum')
                    ->money('PKR')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('schedule_total')
                    ->label('Schedule')
                    ->money('PKR')
                    ->alignEnd()
                    ->state(fn (Contract $record): float => $record->scheduleTotal())
                    ->tooltip('The priced lines. Agreed to differ from the contract sum by whatever was not broken down.')
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Contract::STATUS_DRAFT => 'warning',
                        Contract::STATUS_TERMINATED => 'danger',
                        Contract::STATUS_CLOSED, Contract::STATUS_COMPLETED => 'gray',
                        default => 'success',
                    }),

                TextColumn::make('defects_expiry')
                    ->label('Defects expiry')
                    ->date()
                    // Computed, and an em dash while either half of the answer is missing.
                    ->state(fn (Contract $record) => $record->defectsPeriodExpiry())
                    ->placeholder('—')
                    ->tooltip(fn (Contract $record): string => $record->vocabulary()->defectsPeriod())
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('job_id')
                    ->label('Job')
                    ->relationship('job', 'code')
                    ->searchable(),

                SelectFilter::make('side')
                    ->options([
                        Contract::SIDE_RECEIVABLE => 'Receivable',
                        Contract::SIDE_PAYABLE => 'Payable',
                    ]),

                SelectFilter::make('status')
                    ->options([
                        Contract::STATUS_DRAFT => 'Draft',
                        Contract::STATUS_EXECUTED => 'Executed',
                        Contract::STATUS_IN_PROGRESS => 'In progress',
                        Contract::STATUS_COMPLETED => 'Completed',
                        Contract::STATUS_CLOSED => 'Closed',
                        Contract::STATUS_TERMINATED => 'Terminated',
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make()
                    // Hidden rather than disabled once executed: the schedule is frozen and the service would
                    // refuse, and a button that always refuses is one people click twice.
                    ->visible(fn (Contract $record): bool => $record->isDraft()),

                Action::make('execute')
                    ->label('Execute')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Execute this contract')
                    ->modalDescription('The scheduled values stop following quantity × rate and become the figures the parties signed. Every certificate from here is measured against them, and a change to the schedule after this is a variation.')
                    ->visible(fn (Contract $record): bool => auth()->user()?->can('execute', $record) ?? false)
                    ->action(function (Contract $record): void {
                        try {
                            app(ContractService::class)->execute($record);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Executed.')
                            ->body('The schedule is frozen. Changes from here are variations.')
                            ->send();
                    }),
            ]);
    }
}
