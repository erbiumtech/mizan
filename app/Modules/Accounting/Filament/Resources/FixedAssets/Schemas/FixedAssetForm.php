<?php

namespace App\Modules\Accounting\Filament\Resources\FixedAssets\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Accounting\Models\FixedAsset;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class FixedAssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Select::make('account_id')
                    ->label('Asset Account')
                    ->relationship(
                        'account',
                        'name',
                        fn (Builder $query) => $query->postable()->ofType('asset'),
                    )
                    ->searchable()
                    ->preload()
                    ->required(),

                DatePicker::make('purchase_date')
                    ->label('Purchase Date')
                    ->required(),

                TextInput::make('purchase_cost')
                    ->label('Purchase Cost')
                    ->numeric()
                    ->step(0.01)
                    ->required()
                    ->minValue(0.01),

                Select::make('depreciation_method')
                    ->label('Depreciation Method')
                    ->options([
                        'straight_line' => 'Straight Line',
                        'declining_balance' => 'Declining Balance',
                    ])
                    ->default('straight_line')
                    ->required(),

                TextInput::make('useful_life_months')
                    ->label('Useful Life (months)')
                    ->numeric()
                    ->integer()
                    ->required()
                    ->minValue(1),

                TextInput::make('salvage_value')
                    ->label('Salvage Value')
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0),

                ...CustomFieldsSchema::form(FixedAsset::class),
            ]);
    }
}
