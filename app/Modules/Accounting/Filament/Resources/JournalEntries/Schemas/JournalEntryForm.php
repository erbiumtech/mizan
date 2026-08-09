<?php

namespace App\Modules\Accounting\Filament\Resources\JournalEntries\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class JournalEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('entry_date')
                    ->label('Entry Date')
                    ->required(),

                Select::make('entry_type')
                    ->label('Entry Type')
                    ->options([
                        'general' => 'General',
                        'adjusting' => 'Adjusting',
                        'closing' => 'Closing',
                        'reversing' => 'Reversing',
                    ])
                    ->default('general'),

                // Deliberately no Status field. Status only ever moves through
                // JournalEntryService — Submit for Approval, Approve, Reject, Post,
                // Reverse — each of which enforces its own rule (balanced lines,
                // segregation of duties, an open fiscal year). A form field here
                // would let anyone who can edit a Draft skip straight to Posted:
                // no approval, no segregation-of-duty check, and no update to the
                // account balances that only post() applies. The table's row
                // actions are the only place status changes.
                TextInput::make('reference')
                    ->label('Reference')
                    ->nullable(),

                Textarea::make('memo')
                    ->label('Memo')
                    ->nullable(),

                Select::make('fiscal_year_id')
                    ->label('Fiscal Year')
                    ->relationship('fiscalYear', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable(),
            ]);
    }
}
