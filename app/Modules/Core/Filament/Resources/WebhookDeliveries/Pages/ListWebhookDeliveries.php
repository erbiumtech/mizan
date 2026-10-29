<?php

namespace App\Modules\Core\Filament\Resources\WebhookDeliveries\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\WebhookDeliveries\WebhookDeliveryResource;
use Filament\Resources\Pages\ListRecords;

class ListWebhookDeliveries extends ListRecords
{
    protected static string $resource = WebhookDeliveryResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('webhook-deliveries', 'Webhook Deliveries: Help')];
    }
}
