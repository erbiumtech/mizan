<?php

namespace App\Modules\ConstructionField\Filament\Resources\DelayEvents\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionField\Filament\Resources\DelayEvents\DelayEventResource;
use Filament\Resources\Pages\EditRecord;

class EditDelayEvent extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = DelayEventResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-delay-events', 'Delay events: Help')];
    }
}
