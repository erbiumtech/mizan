<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Trades\Tables;

use App\Modules\ConstructionCosting\Models\Trade;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TradesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('description')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('defaultCostCode.code')
                    ->label('Usual code')
                    ->placeholder('—')
                    ->toggleable(),

                // How many rates the ladder holds for this trade, which is the one number that says whether the
                // trade has been priced at all. A trade with none costs nothing, and §7.2's resolver returns null
                // rather than guessing.
                TextColumn::make('rates_count')
                    ->label('Rates')
                    ->counts('rates')
                    ->alignEnd()
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('In use')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('active')
                    ->label('In use only')
                    ->query(fn (Builder $query) => $query->active())
                    ->default()
                    ->toggle(),
            ])
            ->defaultSort('sort')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Trade $record): bool => auth()->user()?->can('update', $record) ?? false),
            ])
            ->emptyStateHeading('No trades yet')
            ->emptyStateDescription('A trade is what a pair of hands does — steel fixer, mason, carpenter. Labour is booked against one, and what an hour costs is set per trade in Labour Rates.');
    }
}
