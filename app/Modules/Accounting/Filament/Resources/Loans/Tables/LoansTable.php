<?php

namespace App\Modules\Accounting\Filament\Resources\Loans\Tables;

use App\Modules\Accounting\Models\Loan;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class LoansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Loan $record): ?string => $record->lender),

                TextColumn::make('principal')
                    ->label('Borrowed')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('annual_rate')
                    ->label('Rate')
                    ->formatStateUsing(fn ($state): string => rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'%')
                    ->alignEnd(),

                TextColumn::make('term_months')
                    ->label('Term')
                    ->formatStateUsing(fn ($state): string => $state.' mo')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('progress')
                    ->label('Paid')
                    ->state(fn (Loan $record): string => $record->paidCount().' of '.$record->term_months)
                    ->badge()
                    ->color(fn (Loan $record): string => match (true) {
                        $record->paidCount() === 0 => 'gray',
                        $record->paidCount() >= $record->term_months => 'success',
                        default => 'info',
                    }),

                TextColumn::make('outstanding')
                    ->label('Still owed')
                    ->state(fn (Loan $record): float => $record->scheduledOutstanding())
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->tooltip('What the agreement says is left after the instalments recorded so far. Reconcile the loan account against this.'),

                TextColumn::make('next')
                    ->label('Next due')
                    ->state(fn (Loan $record): string => $record->nextDue()?->due_on?->format('d M Y') ?? 'finished')
                    ->color(fn (string $state): string => $state === 'finished' ? 'success' : 'gray'),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }
}
