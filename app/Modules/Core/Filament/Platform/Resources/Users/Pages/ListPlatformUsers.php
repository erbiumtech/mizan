<?php

namespace App\Modules\Core\Filament\Platform\Resources\Users\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Platform\Resources\Users\PlatformUserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlatformUsers extends ListRecords
{
    protected static string $resource = PlatformUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('platform-users', 'Platform Users: Help'),
            CreateAction::make(),
        ];
    }
}
