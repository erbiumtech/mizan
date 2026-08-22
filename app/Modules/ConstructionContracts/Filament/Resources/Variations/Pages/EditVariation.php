<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\Variations\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionContracts\Filament\Resources\Variations\VariationResource;
use Filament\Resources\Pages\EditRecord;

class EditVariation extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = VariationResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-variations', 'Variations: Help')];
    }
}
