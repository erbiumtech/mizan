<?php

namespace App\Modules\Inventory\Filament\Resources\StockLocations\Schemas;

use App\Modules\Accounting\Models\Account;
use App\Modules\Inventory\Models\StockLocation;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class StockLocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(32)
                    ->helperText('Short and yours: MAIN, SITE-01, VAN-3. Unique.'),

                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Select::make('kind')
                    ->options(StockLocation::KINDS)
                    ->default(StockLocation::KIND_WAREHOUSE)
                    ->selectablePlaceholder(false)
                    // `transit` looks like a workaround and is not: a transfer is two movements, and without somewhere
                    // to be in between, stock that has left one store and not arrived is either at both or at neither.
                    ->helperText('A site store belongs to a construction job; in-transit holds stock between two locations during a transfer.'),

                Select::make('inventory_account_id')
                    ->label('Inventory account')
                    ->options(fn (): array => Account::query()->orderBy('code')->get()
                        ->mapWithKeys(fn (Account $account): array => [$account->getKey() => "{$account->code} — {$account->name}"])
                        ->all())
                    ->searchable()
                    ->helperText('Only where you split inventory by location. Blank means the product\'s own account applies, which is what happens today.'),

                Textarea::make('address')
                    ->rows(2)
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('In use')
                    ->default(true)
                    ->helperText('Switching a location off takes it out of the pickers and leaves its movements alone. There is no delete: movements would be left at no location, which reads as a healthy total in the wrong place.'),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
