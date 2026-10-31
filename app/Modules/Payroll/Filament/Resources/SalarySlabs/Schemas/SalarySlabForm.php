<?php

namespace App\Modules\Payroll\Filament\Resources\SalarySlabs\Schemas;

use App\Modules\Payroll\Models\SalarySlab;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SalarySlabForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('fiscal_year_id')
                    ->label(__('Fiscal Year'))
                    ->relationship('fiscalYear', 'name', fn ($query) => $query->where('is_active', true))
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('min_amount')
                    ->label(__('Minimum Amount (Annual)'))
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    // A slab covers income above its minimum up to its maximum; two that overlap in one year
                    // leave the tax depending on which one TaxCalculatorService happens to pick.
                    ->rules([
                        fn (Get $get, ?SalarySlab $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                            $max = filled($get('max_amount')) ? (float) $get('max_amount') : null;

                            $overlapping = SalarySlab::query()
                                ->where('fiscal_year_id', $get('fiscal_year_id'))
                                ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                ->when($max !== null, fn ($query) => $query->where('min_amount', '<', $max))
                                ->where(fn ($query) => $query->whereNull('max_amount')->orWhere('max_amount', '>', (float) $value))
                                ->first();

                            if ($overlapping) {
                                $fail(sprintf(
                                    'This overlaps the %s – %s slab already in this fiscal year.',
                                    number_format((float) $overlapping->min_amount),
                                    $overlapping->max_amount === null ? 'and above' : number_format((float) $overlapping->max_amount),
                                ));
                            }
                        },
                    ])
                    ->helperText('Annual income starting range, e.g., 600001'),

                TextInput::make('max_amount')
                    ->label(__('Maximum Amount (Annual)'))
                    ->numeric()
                    ->nullable()
                    ->helperText('Annual income ending range.'),

                TextInput::make('fixed_tax')
                    ->label(__('Fixed Tax Amount'))
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->default(0)
                    ->helperText('Slab fixed tax amount, e.g., 6000 or 116000'),

                TextInput::make('percentage')
                    ->label(__('Tax Percentage (%)'))
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
