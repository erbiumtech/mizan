<?php

namespace App\Modules\ConstructionField\Filament\Resources\DelayEvents\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionField\Filament\Resources\DelayEvents\DelayEventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDelayEvents extends ListRecords
{
    protected static string $resource = DelayEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-delay-events', 'Delay events: Help'),
            CreateAction::make(),
        ];
    }
}
