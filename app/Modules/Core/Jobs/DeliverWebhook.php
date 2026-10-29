<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * One delivery, retried — docs/saas-launch-plan.md §14.9.
 *
 * Tenant-aware, which is the default here and is correct: the delivery row and the
 * endpoint are both in the company's own database.
 *
 * **Four attempts over roughly two hours.** A receiver that is down for a deploy is
 * back inside the first two; one that is down for longer is down in a way a fifth
 * attempt will not fix, and the delivery stays in the log for somebody to send again
 * by hand. The backoff is the same shape as the FBR submission's, for the same
 * reason: the far end is somebody else's system and the thing to be is patient
 * rather than quick.
 *
 * The id, not the model. A delivery that is retried an hour later must be read as it
 * stands then, not as it was when the job was queued.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public int $timeout = 30;

    public function __construct(public int $deliveryId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 600, 3600];
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::with('endpoint')->find($this->deliveryId);
        $endpoint = $delivery?->endpoint;

        // Deleted while queued, or the endpoint was. Nothing to deliver and nothing wrong.
        if ($delivery === null || $endpoint === null || $delivery->isDelivered()) {
            return;
        }

        $body = (string) json_encode([
            'event' => $delivery->event,
            'delivery' => $delivery->getKey(),
            'sent_at' => now()->toIso8601String(),
            'data' => $delivery->payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $delivery->increment('attempts');

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            // The names a receiver checks. Prefixed rather than bare, because a proxy
            // in front of somebody's endpoint will pass through what it does not know.
            'X-Mizan-Event' => (string) $delivery->event,
            'X-Mizan-Delivery' => (string) $delivery->getKey(),
            'X-Mizan-Signature' => 'sha256='.$endpoint->sign($body),
        ])->timeout(15)->withBody($body, 'application/json')->post((string) $endpoint->url);

        $delivery->update([
            'response_code' => $response->status(),
            'status' => $response->successful() ? WebhookDelivery::STATUS_DELIVERED : WebhookDelivery::STATUS_PENDING,
            'delivered_at' => $response->successful() ? now() : null,
            'error' => $response->successful() ? null : \Illuminate\Support\Str::limit((string) $response->body(), 500),
        ]);

        if (! $response->successful()) {
            // Thrown so the queue retries it on the schedule above. The row already says
            // what happened, so the exception is for the worker rather than for a person.
            throw new RuntimeException("The endpoint answered {$response->status()}.");
        }

        $endpoint->update(['consecutive_failures' => 0, 'last_delivered_at' => now()]);
    }

    /**
     * Out of attempts.
     *
     * The endpoint's failure run is counted here rather than per attempt, so a receiver
     * that is briefly down does not walk towards being switched off on retries of one
     * event.
     */
    public function failed(?Throwable $exception): void
    {
        $delivery = WebhookDelivery::with('endpoint')->find($this->deliveryId);

        $delivery?->update([
            'status' => WebhookDelivery::STATUS_FAILED,
            'error' => \Illuminate\Support\Str::limit($delivery->error ?: ($exception?->getMessage() ?? 'No reason was recorded.'), 500),
        ]);

        $endpoint = $delivery?->endpoint;

        if ($endpoint === null) {
            return;
        }

        $failures = $endpoint->consecutive_failures + 1;

        $endpoint->update([
            'consecutive_failures' => $failures,
            // Switched off rather than retried forever. An endpoint that has failed ten
            // events in a row is gone, and every event after it costs a worker four
            // attempts over two hours to find that out again.
            'is_active' => $failures < $endpoint::FAILURE_LIMIT,
        ]);
    }
}
