<?php

namespace App\Modules\Core\Filament\Resources\TableViews\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TableViewForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('resource')->disabled()->dehydrated(false),
            Select::make('icon')->options([
                'heroicon-o-star' => 'Star', 'heroicon-o-flag' => 'Flag',
                'heroicon-o-funnel' => 'Filter', 'heroicon-o-clock' => 'Clock',
                'heroicon-o-check-circle' => 'Check',
            ])->native(false)->nullable(),
            Select::make('color')->options([
                'primary' => 'Primary', 'success' => 'Green', 'warning' => 'Amber',
                'danger' => 'Red', 'info' => 'Blue', 'gray' => 'Gray',
            ])->native(false)->nullable(),
            Toggle::make('is_favorite')->label('Favorite'),
            Toggle::make('is_public')->label('Shared with company'),
            Toggle::make('is_global')->label('Global (pinned for everyone)'),
        ]);
    }
}
