<?php

namespace App\Modules\Invoicing\Filament\Resources\Invoices\RelationManagers;

use App\Modules\Invoicing\Models\InvoiceEvent;
use App\Support\LandlordUserColumn;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The document's life, newest first.
 *
 * Read-only by construction: it is a record of what happened, and something that can
 * be edited is not that.
 */
class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'History';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i')->sortable(),

                TextColumn::make('event')
                    ->badge()
                    ->color(fn (InvoiceEvent $record): string => $record->color()),

                TextColumn::make('description')->wrap(),

                TextColumn::make('amount')
                    ->money('PKR')
                    ->placeholder('—')
                    ->alignEnd(),

                TextColumn::make('causer.name')
                    ->label('By')
                    // Nobody, for anything the scheduler or a command did — better
                    // than blaming a user who was not there.
                    ->placeholder('the system')
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search)),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50]);
    }
}
