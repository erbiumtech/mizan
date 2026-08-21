<?php

namespace App\Modules\ConstructionField\Filament\Resources\Rfis\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionField\Filament\Resources\Rfis\RfiResource;
use Filament\Resources\Pages\EditRecord;

class EditRfi extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = RfiResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-rfis', 'RFIs: Help')];
    }
}
