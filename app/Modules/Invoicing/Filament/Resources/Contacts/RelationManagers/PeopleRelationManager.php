<?php

namespace App\Modules\Invoicing\Filament\Resources\Contacts\RelationManagers;

use App\Modules\Invoicing\Models\ContactPerson;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PeopleRelationManager extends RelationManager
{
    protected static string $relationship = 'people';

    protected static ?string $title = 'People';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),

            TextInput::make('title')
                ->label('Role')
                ->maxLength(255)
                ->helperText('What they do there — Accounts Payable, Managing Director.'),

            TextInput::make('email')->email()->maxLength(255),
            TextInput::make('phone')->tel()->maxLength(255),

            Toggle::make('is_primary')
                ->label('Main contact')
                ->helperText('Correspondence goes here. Only one person can be the main contact; setting this stands the others down.'),

            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->description(fn (ContactPerson $record): ?string => $record->title),

                TextColumn::make('email')->searchable()->placeholder('—')->copyable(),
                TextColumn::make('phone')->placeholder('—'),
                IconColumn::make('is_primary')->label('Main')->boolean(),
            ])
            ->defaultSort('is_primary', 'desc')
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
