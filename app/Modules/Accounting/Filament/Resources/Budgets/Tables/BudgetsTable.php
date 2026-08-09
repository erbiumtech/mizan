<?php

namespace App\Modules\Accounting\Filament\Resources\Budgets\Tables;

use App\Modules\Accounting\Models\Budget;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class BudgetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('fiscalYear.name')
                    ->label('Fiscal Year')
                    ->sortable(),

                TextColumn::make('lines_count')
                    ->label('Accounts')
                    ->counts([
                        // Rows are per account per month, so a straight count
                        // reads twelve times too high — "Accounts: 240" for a
                        // twenty-account plan.
                        'lines' => fn ($query) => $query->distinct('account_id'),
                    ])
                    ->badge()
                    ->color('gray'),

                TextColumn::make('planned_total')
                    ->label('Planned (year)')
                    ->state(fn (Budget $budget): float => round((float) $budget->lines()->sum('amount'), 2))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('fiscal_year_id')
                    ->label('Fiscal Year')
                    ->relationship('fiscalYear', 'name'),

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
