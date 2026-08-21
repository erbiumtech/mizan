<?php

namespace App\Modules\ConstructionField\Filament\Resources\PunchLists\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionField\Filament\Resources\PunchLists\PunchListResource;
use Filament\Resources\Pages\EditRecord;

class EditPunchList extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = PunchListResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-punch-lists', 'Punch lists: Help')];
    }
}
