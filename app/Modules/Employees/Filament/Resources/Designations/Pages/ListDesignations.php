<?php

namespace App\Modules\Employees\Filament\Resources\Designations\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Employees\Filament\Resources\Designations\DesignationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDesignations extends ListRecords
{
    protected static string $resource = DesignationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('designations', 'Designations: Help'),
            CreateAction::make(),
        ];
    }
}
