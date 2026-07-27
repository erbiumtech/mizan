<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Models\Company;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            // Only asked on create — the assigned user becomes this company's
            // Administrator (attached + given the Administrator role in its team).
            Select::make('admin_user_id')
                ->label('Company Admin')
                ->helperText('This user is added to the company as its Administrator.')
                ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required()
                ->dehydrated()
                ->visible(fn (?Company $record) => $record === null),

            Select::make('status')
                ->options([1 => 'Active', 0 => 'Inactive'])
                ->default(1)
                ->visible(fn (?Company $record) => $record !== null),

            TextInput::make('slug')
                ->disabled()
                ->dehydrated(false)
                ->visible(fn (?Company $record) => $record !== null),
        ]);
    }
}
