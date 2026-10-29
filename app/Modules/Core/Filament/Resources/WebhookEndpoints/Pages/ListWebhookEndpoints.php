<?php

namespace App\Modules\Core\Filament\Resources\WebhookEndpoints\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWebhookEndpoints extends ListRecords
{
    protected static string $resource = WebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('webhooks', 'Webhooks: Help'),
            CreateAction::make(),
        ];
    }
}
