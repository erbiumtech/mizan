<?php

namespace App\Modules\Core\Filament\Platform\Resources\Permissions\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PermissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),

                TextInput::make('group')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Module the permission belongs to (used to group the Role permission picker).'),

                Select::make('guard_name')
                    ->options(['web' => 'web'])
                    ->default('web')
                    ->required(),
            ])
            ->columns(2);
    }
}
