<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Jobs\DeliverWebhook;
use App\Modules\Core\Models\WebhookDelivery;
use App\Modules\Core\Models\WebhookEndpoint;
use App\Support\Webhooks;
use Throwable;

/**
 * A domain event reaching the endpoints that asked for it — docs/saas-launch-plan.md §14.9.
 *
 * One listener for every registered type rather than one per module: what varies is
 * the payload, and that is the registry's business.
 *
 * **Nothing here may throw.** This runs inside a hire, a payslip or a sign-in, and a
 * webhook configuration cannot be the reason one of those fails. A company with no
 * endpoints pays one indexed query for the privilege, and a tenant whose table has
 * not been migrated yet pays an exception nobody sees.
 */
class FanOutWebhooks
{
    public function __construct(private readonly string $key) {}

    public function handle(object $event): void
    {
        try {
            $endpoints = WebhookEndpoint::query()->active()->get()
                ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->wants($this->key));

            if ($endpoints->isEmpty()) {
                return;
            }

            $payload = Webhooks::payloadFor($this->key, $event);

            if ($payload === null) {
                return;
            }

            foreach ($endpoints as $endpoint) {
                // The row before the attempt: a delivery that never ran because the queue
                // died has to look different from one that was never recorded.
                $delivery = WebhookDelivery::create([
                    'webhook_endpoint_id' => $endpoint->getKey(),
                    'event' => $this->key,
                    'payload' => $payload,
                ]);

                DeliverWebhook::dispatch($delivery->getKey());
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
