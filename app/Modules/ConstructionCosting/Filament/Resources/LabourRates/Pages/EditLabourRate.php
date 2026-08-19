<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\LabourRates\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRates\LabourRateResource;
use Filament\Resources\Pages\EditRecord;

class EditLabourRate extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = LabourRateResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-labour-rates', 'Labour rates: Help')];
    }
}
