<?php

namespace App\Modules\Core\Filament\Platform\Resources\Permissions\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Platform\Resources\Permissions\PermissionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPermissions extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = PermissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('permissions', 'Permissions: Help'),
            CreateAction::make(),
        ];
    }
}
