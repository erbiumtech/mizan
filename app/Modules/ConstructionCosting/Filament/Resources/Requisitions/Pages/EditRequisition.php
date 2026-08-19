<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Requisitions\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\Requisitions\RequisitionResource;
use Filament\Resources\Pages\EditRecord;

class EditRequisition extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = RequisitionResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-requisitions', 'Requisitions: Help')];
    }
}
