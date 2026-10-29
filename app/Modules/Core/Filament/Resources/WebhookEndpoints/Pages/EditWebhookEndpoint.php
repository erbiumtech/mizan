<?php

namespace App\Modules\Core\Filament\Resources\WebhookEndpoints\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWebhookEndpoint extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = WebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
