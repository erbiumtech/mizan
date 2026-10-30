<?php

namespace App\Modules\Core\Filament\Resources\Holidays\Schemas;

use App\Modules\Core\Models\Holiday;
use App\Modules\Core\Models\WorkLocation;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
                // 500 on save. Compared as a date: SQLite stores the cast value as
                // `Y-m-d 00:00:00`, which a plain `unique` rule never matches.
                DatePicker::make('date')
                    ->label('Date')
                    ->native(false)
                    ->required()
                    ->rule(fn (?Holiday $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                        if (Holiday::query()->whereDate('date', $value)->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))->exists()) {
                            $fail('There is already a holiday on this date.');
                        }
                    }),

                TextInput::make('name')
                    ->label('Name')
                    ->required(),
                // docs/saas-launch-plan.md §14.4. Only a public holiday closes the company.
                Select::make('kind')
                    ->options(Holiday::KINDS)
                    ->default(Holiday::KIND_PUBLIC)
                    ->required(),

                // Company-wide unless a site is named — plan §7.9. Leave days
                // already generated are not recomputed when one is added.
                Select::make('location_id')
                    ->label('Location')
                    ->options(fn (): array => WorkLocation::options())
                    ->placeholder('Company-wide')
                    ->nullable()
                    ->helperText('Leave blank for every site. A local holiday counts only for employees at that location.'),

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
