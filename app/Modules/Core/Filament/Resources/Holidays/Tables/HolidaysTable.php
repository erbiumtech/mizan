<?php

namespace App\Modules\Core\Filament\Resources\Holidays\Tables;

use App\Modules\Core\Models\Holiday;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class HolidaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // A calendar is read as a calendar: chronologically, and the year
            // being planned is the one at the top.
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')
                    ->label('Date')
                    ->date('d M Y')
                    // The weekday, because a holiday that already falls on a
                    // non-working day is the one somebody wants to spot.
                    ->description(fn (Holiday $record): string => $record->date->format('l'))
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(),

                IconColumn::make('is_recurring')
                    ->label('Recurring')
                    ->boolean(),

                TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_recurring')
                    ->label('Recurring'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
