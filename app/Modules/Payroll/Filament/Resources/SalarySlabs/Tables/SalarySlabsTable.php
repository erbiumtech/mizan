<?php

namespace App\Modules\Payroll\Filament\Resources\SalarySlabs\Tables;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Models\SalarySlab;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalarySlabsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('fiscalYear.name')
                    ->label('Fiscal Year')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('min_amount')
                    ->label('Minimum Amount (Annual)')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('max_amount')
                    ->label('Maximum Amount (Annual)')
                    ->numeric(),

                TextColumn::make('fixed_tax')
                    ->label('Fixed Tax Amount')
                    ->numeric(),

                TextColumn::make('percentage')
                    ->label('Tax Percentage (%)')
                    ->numeric(),

                TextColumn::make('slab_preview')
                    ->label('Slab Preview')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->state(function (SalarySlab $record): string {
                        $max = $record->max_amount ? number_format($record->max_amount) : 'Above';
                        $min = number_format($record->min_amount);
                        $tax = number_format($record->fixed_tax);

                        return "PKR {$min} to {$max} ➔ Fixed: PKR {$tax} + {$record->percentage}%";
                    }),
            ])
            ->filters([
                SelectFilter::make('fiscal_year_id')
                    ->label('Fiscal Year')
                    ->options(fn (): array => FiscalYear::pluck('name', 'id')->toArray()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
