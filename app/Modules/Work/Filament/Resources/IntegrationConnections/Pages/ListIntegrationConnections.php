<?php

namespace App\Modules\Work\Filament\Resources\IntegrationConnections\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Work\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIntegrationConnections extends ListRecords
{
    protected static string $resource = IntegrationConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Connect'),
            HelpAction::make('work-integrations', 'Integrations: Help'),
        ];
    }
}
