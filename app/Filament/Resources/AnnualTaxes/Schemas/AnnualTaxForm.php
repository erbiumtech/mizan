<?php

namespace App\Filament\Resources\AnnualTaxes\Schemas;

use App\Models\AnnualTax;
use App\Models\Employee;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class AnnualTaxForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'employee_id')
                    ->getOptionLabelFromRecordUsing(fn (Employee $record): string => $record->employee_id.' - '.($record->user?->name ?? ''))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->rules([
                        fn (Get $get, ?AnnualTax $record) => function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                            $exists = AnnualTax::where('employee_id', $value)
                                ->where('fiscal_year_id', $get('fiscal_year_id'))
                                ->when($record, fn (Builder $q) => $q->where('id', '!=', $record->id))
                                ->exists();

                            if ($exists) {
                                $fail('Annual Tax record of this employee for this fiscal year is already existed');
                            }
                        },
                    ]),

                Select::make('fiscal_year_id')
                    ->label('Fiscal Year')
                    ->relationship('fiscalYear', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('total_net_income')
                    ->label('Total Net Income')
                    ->numeric()
                    ->step(0.01)
                    ->readOnly(),

                TextInput::make('annual_income_tax')
                    ->label('Annual Taxable Income')
                    ->numeric()
                    ->step(0.01)
                    ->readOnly(),

                TextInput::make('total_annual_tax')
                    ->label('Total Annual Tax')
                    ->numeric()
                    ->step(0.01)
                    ->readOnly(),

                TextInput::make('paid_tax')
                    ->label('Paid Tax')
                    ->numeric()
                    ->step(0.01)
                    ->readOnly(),

                TextInput::make('leftover_tax')
                    ->label('Leftover Tax')
                    ->numeric()
                    ->step(0.01)
                    ->readOnly(),
            ]);
    }
}
