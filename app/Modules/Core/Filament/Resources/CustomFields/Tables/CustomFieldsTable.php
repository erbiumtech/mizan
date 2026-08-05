<?php

namespace App\Modules\Core\Filament\Resources\CustomFields\Tables;

use App\Modules\Core\Filament\Resources\CustomFields\CustomFieldResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CustomFieldsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('model_type')
                    ->label('Applies to')
                    ->formatStateUsing(fn (string $state) => CustomFieldResource::modelOptions()[$state] ?? class_basename($state))
                    ->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('type')->badge(),
                IconColumn::make('is_required')->boolean()->label('Required'),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('sort')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort')
            ->filters([
                SelectFilter::make('model_type')->label('Applies to')->options(CustomFieldResource::modelOptions()),
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
