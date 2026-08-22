<?php

namespace App\Modules\Inventory\Filament\Resources\StockLocations\Tables;

use App\Modules\Inventory\Models\StockLocation;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockLocationsTable
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

                TextColumn::make('kind')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        StockLocation::KIND_SITE => 'warning',
                        StockLocation::KIND_TRANSIT => 'gray',
                        default => 'info',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->sortable(),

                TextColumn::make('movements_count')
                    ->label('Movements')
                    ->counts('movements')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('inventoryAccount.code')
                    ->label('Account')
                    ->placeholder('product default')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('In use')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(StockLocation::KINDS),

                Filter::make('active')
                    ->label('In use only')
                    ->query(fn (Builder $query) => $query->active())
                    ->default()
                    ->toggle(),
            ])
            ->defaultSort('code')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (StockLocation $record): bool => auth()->user()?->can('update', $record) ?? false),
            ])
            ->emptyStateHeading('No stock locations')
            ->emptyStateDescription('A company keeping stock in one place needs none of these. Add them when stock is in more than one place — a second warehouse, a shop, a van, or a construction site store.');
    }
}
