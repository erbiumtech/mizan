<?php

namespace App\Modules\Core\Filament\Resources\Roles\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\Roles\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('roles', 'Roles: Help'),
            CreateAction::make(),
        ];
    }
}
