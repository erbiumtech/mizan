<?php

namespace App\Filament\Resources\FixedAssets\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class JournalEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'journalEntries';

    protected static ?string $title = 'Journal Entries';

    protected static ?string $recordTitleAttribute = 'entry_number';

    public function table(Table $table): Table
    {
        // Depreciation/disposal entries are booked by DepreciationService only —
        // read-only here.
        return $table
            ->columns([
                TextColumn::make('entry_number')
                    ->label('Entry Number')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('entry_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('memo')
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->defaultSort('entry_date', 'desc');
    }
}
