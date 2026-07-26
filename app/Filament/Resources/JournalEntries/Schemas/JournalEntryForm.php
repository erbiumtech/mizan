<?php

namespace App\Filament\Resources\JournalEntries\Schemas;

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

                Select::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'pending_approval' => 'Pending Approval',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'posted' => 'Posted',
                    ]),

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
