<?php

namespace App\Modules\ConstructionField\Filament\Resources\Submittals\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionField\Filament\Resources\Submittals\SubmittalResource;
use Filament\Resources\Pages\EditRecord;

class EditSubmittal extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = SubmittalResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-submittals', 'Submittals: Help')];
    }
}
