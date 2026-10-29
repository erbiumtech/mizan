<?php

namespace App\Modules\Employees\Filament\Resources\Announcements\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Employees\Filament\Resources\Announcements\AnnouncementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAnnouncements extends ListRecords
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('announcements', 'Announcements: Help'),
            CreateAction::make(),
        ];
    }
}
