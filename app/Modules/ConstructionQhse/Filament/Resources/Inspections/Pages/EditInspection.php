<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Inspections\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionQhse\Filament\Resources\Inspections\InspectionResource;
use Filament\Resources\Pages\EditRecord;

class EditInspection extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = InspectionResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-itps', 'ITPs and inspections: Help')];
    }
}
