<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Requisitions\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\Requisitions\RequisitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRequisitions extends ListRecords
{
    protected static string $resource = RequisitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-requisitions', 'Requisitions: Help'),
            CreateAction::make(),
        ];
    }
}
