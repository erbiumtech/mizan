<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Workers\Tables;

use App\Modules\ConstructionCosting\Models\Worker;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The gang list.
 *
 * **The *Engaged as* column is the one that earns its place**, because it is the distinction the HR register cannot
 * make: employee, direct-paid, or supplied by somebody else. §7.1's whole argument for this table is that all three
 * work on the same site and only the first belongs in Employees.
 */
class WorkersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Number')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('trade.code')
                    ->label('Trade')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('engagement')
                    ->label('Engaged as')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        // Neither colour is a warning: all three are ordinary on a site, which is the point.
                        Worker::ENGAGEMENT_EMPLOYEE => 'success',
                        Worker::ENGAGEMENT_SUPPLIED => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),

                TextColumn::make('supplier.name')
                    ->label('Supplied by')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('phone')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('started_on')
                    ->label('Started')
                    ->date()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('ended_on')
                    ->label('Left')
                    ->date()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('In use')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('engagement')
                    ->label('Engaged as')
                    ->options(Worker::ENGAGEMENTS),

                SelectFilter::make('trade_id')
                    ->label('Trade')
                    ->relationship('trade', 'name')
                    ->searchable(),

                Filter::make('active')
                    ->label('In use only')
                    ->query(fn (Builder $query) => $query->active())
                    ->default()
                    ->toggle(),
            ])
            ->defaultSort('code')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Worker $record): bool => auth()->user()?->can('update', $record) ?? false),
            ])
            ->emptyStateHeading('Nobody on the register yet')
            ->emptyStateDescription('Everybody who works on a site goes here — on the payroll or not. Labour cost is booked against these rows, and the rate comes from the Labour Rates ladder.');
    }
}
