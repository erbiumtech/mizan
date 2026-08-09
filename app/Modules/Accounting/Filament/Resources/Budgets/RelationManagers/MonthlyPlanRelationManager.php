<?php

namespace App\Modules\Accounting\Filament\Resources\Budgets\RelationManagers;

use App\Modules\Accounting\Filament\Resources\Budgets\Schemas\BudgetForm;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The evenly-spread months, open for adjustment.
 *
 * This is the "per-month override" half of the plan. The form on the previous
 * tab takes one figure a year because that is what anybody will actually sit and
 * type; this is where the four months of school fees, or the bonus in December,
 * get put where they really fall.
 *
 * Amounts only. Adding or removing a month here would leave the budget with a
 * year it does not cover, or two rows fighting over one month — the set of
 * months is the fiscal year's, and it is not the reader's to change.
 */
class MonthlyPlanRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Monthly Plan';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('amount')
                ->label('Planned for this month')
                ->numeric()
                ->minValue(0)
                ->required()
                ->prefix('PKR')
                ->helperText('Changing a month changes the year\'s total for this account by the same amount.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account.code')
                    ->label('Code')
                    ->sortable(),

                TextColumn::make('account.name')
                    ->label('Account')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('period_start')
                    ->label('Month')
                    ->date('M Y')
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Planned')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->summarize(
                        \Filament\Tables\Columns\Summarizers\Sum::make()->label('Total')->numeric(decimalPlaces: 2)
                    ),
            ])
            ->filters([
                SelectFilter::make('account_id')
                    ->label('Account')
                    ->options(fn (): array => BudgetForm::plannableAccounts())
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('period_start')
            ->emptyStateHeading('No plan yet')
            ->emptyStateDescription('Add accounts and yearly figures on the Budget tab; the months appear here.');
    }
}
