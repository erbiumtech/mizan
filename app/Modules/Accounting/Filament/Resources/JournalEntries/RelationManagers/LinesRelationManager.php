<?php

namespace App\Modules\Accounting\Filament\Resources\JournalEntries\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('account_id')
                    ->label('Account')
                    ->relationship(
                        'account',
                        'name',
                        fn ($query) => $query->where('is_active', true)
                            ->where('allow_manual_entry', true)
                            ->whereDoesntHave('children'),
                    )
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('debit_amount')
                    ->label('Debit')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required(),

                TextInput::make('credit_amount')
                    ->label('Credit')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required(),

                TextInput::make('description')
                    ->label('Description')
                    ->nullable(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('account.name')
                    ->label('Account')
                    ->sortable(),

                TextColumn::make('debit_amount')
                    ->label('Debit')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('credit_amount')
                    ->label('Credit')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Description')
                    ->searchable(),
            ])
            ->headerActions([
                CreateAction::make(),
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
