<?php

namespace App\Modules\Accounting\Filament\Resources\Banks\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BanksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('ID'))
                    ->sortable(),

                TextColumn::make('bank_code')
                    ->label(__('Bank Code'))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('bank_name')
                    ->label(__('Bank Name'))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('bank_short_code')
                    ->label(__('Bank Short Code'))
                    ->sortable()
                    ->searchable(),

                // docs/saas-launch-plan.md §14.10. Toggled off by default: most companies
                // never make a payment that needs either.
                TextColumn::make('swift_code')->label(__('SWIFT'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('iban_prefix')->label(__('IBAN code'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                //
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
