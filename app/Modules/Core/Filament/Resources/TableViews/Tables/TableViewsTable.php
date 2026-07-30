<?php

namespace App\Modules\Core\Filament\Resources\TableViews\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TableViewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('user.name')->label('Owner')->searchable()->sortable(),
                TextColumn::make('resource')->label('Table')->limit(40)->toggleable(),
                IconColumn::make('is_favorite')->boolean()->label('Fav'),
                IconColumn::make('is_public')->boolean()->label('Shared'),
                IconColumn::make('is_global')->boolean()->label('Global'),
                IconColumn::make('is_default')->boolean()->label('Default'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_public')->label('Shared'),
                TernaryFilter::make('is_global')->label('Global'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
