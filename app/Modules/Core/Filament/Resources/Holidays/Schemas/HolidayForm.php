<?php

namespace App\Modules\Core\Filament\Resources\Holidays\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class HolidayForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Unique in the database as well. Validating it here too is what
                // turns a duplicate into a message on the field instead of a
                // 500 on save.
                DatePicker::make('date')
                    ->label('Date')
                    ->native(false)
                    ->required()
                    ->unique(ignoreRecord: true),

                TextInput::make('name')
                    ->label('Name')
                    ->required(),

                Toggle::make('is_recurring')
                    ->label('Recurring')
                    // Said plainly because the flag reads as if it generates
                    // next year's row, and it does not — Eid moves, so the date
                    // is somebody's to confirm every year.
                    ->helperText('Offer this holiday again next year. The date is never worked out automatically.'),

                Textarea::make('notes')
                    ->rows(2)
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }
}
