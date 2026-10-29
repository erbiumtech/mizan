<?php

namespace App\Modules\Core\Filament\Resources\WebhookEndpoints\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateWebhookEndpoint extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = WebhookEndpointResource::class;

    /**
     * The secret, once.
     *
     * Shown here and nowhere afterwards, for the reason a password is: a secret a
     * screen can show again is a secret anybody who reaches the screen can read. A
     * company that loses it deletes the endpoint and makes another, which is a minute's
     * work and the right amount of friction.
     */
    protected function afterCreate(): void
    {
        Notification::make()
            ->success()
            ->persistent()
            ->title('Your signing secret — copy it now')
            ->body($this->record->secret."\n\nIt is not shown again. Deliveries carry an HMAC of the body under it, in the X-Mizan-Signature header.")
            ->send();
    }
}
