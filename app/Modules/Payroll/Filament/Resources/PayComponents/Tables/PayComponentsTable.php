<?php

namespace App\Modules\Payroll\Filament\Resources\PayComponents\Tables;

use App\Modules\Payroll\Models\PayComponent;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PayComponentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->searchable()->sortable(),
                TextColumn::make('code')->fontFamily('mono')->size('xs')->searchable()->toggleable(),

                TextColumn::make('kind')
                    ->badge()
                    ->color(fn (string $state): string => $state === PayComponent::KIND_EARNING ? 'success' : 'danger'),

                IconColumn::make('is_taxable')
                    ->label('Taxable')
                    ->boolean()
                    ->placeholder('—')
                    // Meaningless on a deduction, so it is left blank rather than
                    // shown as a false that reads like a decision.
                    ->state(fn (PayComponent $record): ?bool => $record->isEarning() ? $record->is_taxable : null),

                TextColumn::make('posts_to')
                    ->label('Posts to')
                    ->state(fn (PayComponent $record): string => $record->account
                        ? $record->account->code.' '.$record->account->name
                        : ($record->account_key ?: '—')),

                // Which of the two kinds of component this is: shipped and still
                // column-backed, or added and driven from here.
                TextColumn::make('is_column_backed')
                    ->label('Source')
                    ->badge()
                    ->state(fn (PayComponent $record): string => $record->is_column_backed ? 'built in' : 'added')
                    ->color(fn (string $state): string => $state === 'built in' ? 'gray' : 'info'),

                TextColumn::make('paid')
                    ->label('Paid to date')
                    ->state(fn (PayComponent $record): string => number_format((float) $record->payslipAmounts()->sum('amount'), 2))
                    ->alignEnd(),

                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('sort')
            ->filters([
                SelectFilter::make('kind')->options([
                    PayComponent::KIND_EARNING => 'Earnings',
                    PayComponent::KIND_DEDUCTION => 'Deductions',
                ]),
            ])
            ->recordActions([EditAction::make()]);
    }
}
