<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts\Tables;

use App\Modules\ConstructionCosting\Models\ControlAccount;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ControlAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('kind')
            ->columns([
                TextColumn::make('account.code')
                    ->label('Account')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('account.name')
                    ->label('Name')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ControlAccount::KINDS[$state] ?? $state),

                // Named rather than shown as a slug: "Labour burden absorbed" is what somebody setting this up is
                // looking for, and `labour_burden` is what the code calls it.
                TextColumn::make('purpose')
                    ->label('Posting rule')
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : (ControlAccount::PURPOSES[$state] ?? $state))
                    ->description(fn (ControlAccount $record): ?string => $record->purpose === null
                        ? 'Report scope only'
                        : null),

                TextColumn::make('cost_type')
                    ->label('Cost type')
                    ->badge()
                    ->placeholder('Any'),

                IconColumn::make('is_active')
                    ->label('In use')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(ControlAccount::KINDS),
                SelectFilter::make('purpose')->options(ControlAccount::PURPOSES),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
