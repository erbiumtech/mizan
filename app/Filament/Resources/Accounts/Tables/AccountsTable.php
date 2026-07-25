<?php

namespace App\Filament\Resources\Accounts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Nova: Text code — sortable, searchable.
                TextColumn::make('code')
                    ->sortable()
                    ->searchable(),

                // Nova: Text name — sortable, searchable.
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),

                // Nova: Select type — sortable, filterable.
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),

                // Nova: Badge normal_balance — debit→info, credit→warning; exceptOnForms.
                TextColumn::make('normal_balance')
                    ->label('Normal Balance')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'debit' => 'info',
                        'credit' => 'warning',
                        default => 'gray',
                    }),

                // Nova: BelongsTo parent → Account — sortable.
                TextColumn::make('parent.name')
                    ->label('Parent Account')
                    ->sortable()
                    ->placeholder('—'),

                // Nova: Boolean is_active (Active) — sortable, filterable.
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                // Nova: Currency balance — exceptOnForms, sortable (already rounded → money PKR).
                TextColumn::make('balance')
                    ->money('PKR')
                    ->sortable(),

                // Nova: Boolean allow_manual_entry — hideFromIndex.
                IconColumn::make('allow_manual_entry')
                    ->label('Allow Manual Entry')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
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
