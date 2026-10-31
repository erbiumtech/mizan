<?php

namespace App\Modules\Core\Filament\Resources\Holidays\Tables;

use App\Filament\Actions\ReplicateRecordAction;
use App\Modules\Core\Models\Holiday;
use App\Modules\Core\Models\WorkLocation;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                    ->label(__('Date'))
                    ->date('d M Y')
                    // The weekday, because a holiday that already falls on a
                    // non-working day is the one somebody wants to spot.
                    ->description(fn (Holiday $record): string => $record->date->format('l'))
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable(),

                TextColumn::make('location.name')
                    ->label(__('Location'))
                    ->placeholder('Company-wide')
                    ->sortable(),

                IconColumn::make('is_recurring')
                    ->label(__('Recurring'))
                    ->boolean(),

                TextColumn::make('notes')
                    ->label(__('Notes'))
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_recurring')
                    ->label(__('Recurring')),
                SelectFilter::make('location_id')
                    ->label(__('Location'))
                    ->options(fn (): array => WorkLocation::options()),
            ])
            ->recordActions([
                EditAction::make(),
                ReplicateRecordAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
