<?php

namespace App\Modules\Core\Filament\Platform\Resources\Roles\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Platform\Resources\Roles\PlatformRoleResource;
use Filament\Resources\Pages\ListRecords;

class ListPlatformRoles extends ListRecords
{
    protected static string $resource = PlatformRoleResource::class;

    /**
     * No Create action, unlike the other platform lists: a role is created for a company by
     * RoleSeeder — from the company panel, or from the Companies screen's re-sync action —
     * so that the five standard names and their permissions stay the same everywhere. A
     * hand-made role here would have no permissions and, with no company in context, no
     * obvious company either.
     */
    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('platform-roles', 'Platform Roles: Help'),
        ];
    }
}
