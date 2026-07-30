<?php

namespace App\Modules\Payroll\Filament\Resources\SalarySlabs\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SalarySlabForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('fiscal_year_id')
                    ->label('Fiscal Year')
                    ->relationship('fiscalYear', 'name', fn ($query) => $query->where('is_active', true))
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('min_amount')
                    ->label('Minimum Amount (Annual)')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->helperText('Annual income starting range, e.g., 600001'),

                TextInput::make('max_amount')
                    ->label('Maximum Amount (Annual)')
                    ->numeric()
                    ->nullable()
                    ->helperText('Annual income ending range.'),

                TextInput::make('fixed_tax')
                    ->label('Fixed Tax Amount')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->default(0)
                    ->helperText('Slab fixed tax amount, e.g., 6000 or 116000'),

                TextInput::make('percentage')
                    ->label('Tax Percentage (%)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->required()
                    ->default(0)
                    ->helperText('Percentage on Exceeding Amount, e.g., 20'),
            ]);
    }
}
