<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Itps\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionQhse\Filament\Resources\Itps\ItpResource;
use Filament\Resources\Pages\EditRecord;

class EditItp extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ItpResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-itps', 'ITPs and inspections: Help')];
    }
}
