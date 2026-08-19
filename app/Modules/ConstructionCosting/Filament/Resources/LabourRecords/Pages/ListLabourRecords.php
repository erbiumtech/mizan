<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\LabourRecordResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLabourRecords extends ListRecords
{
    protected static string $resource = LabourRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-site-sheets', 'Site sheets: Help'),
            CreateAction::make(),
        ];
    }
}
