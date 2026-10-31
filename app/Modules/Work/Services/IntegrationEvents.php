<?php

namespace App\Modules\Work\Services;

use App\Modules\Work\Integrations\CodeProvider;
use App\Modules\Work\Integrations\TimeProvider;
use App\Modules\Work\Jobs\ProcessIntegrationEvent;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\IntegrationEvent;
use App\Support\Integrations;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Inbound: one endpoint, verified before queued — docs/project-management-plan.md §8.3.
 *
 * The signature is checked **synchronously** and a bad one is a 401 that touches no queue. A good
 * one is written to `integration_events` and a job is queued; the handler is idempotent on the
 * provider's event id, because every provider redelivers. A paused connection (§8.6) accepts
 * nothing: the provider's redelivery covers the gap.
 *
 * One endpoint for both kinds (§8.8: Clockify's webhooks "reach the same endpoint as §8.3"). The
 * driver says which it is, and processing hands a code provider's facts to `FactHandler` and a
 * time provider's entries to `TimeSync`.
 */
class IntegrationEvents
{
    public function __construct(
        private readonly FactHandler $facts,
        private readonly TimeSync $time,
    ) {}

    /**
     * Accept a delivery, or refuse it.
     *
     * @return IntegrationEvent|null the stored event; null for a redelivery already held
     *
     * @throws RuntimeException with the HTTP status to answer, as the code
     */
    public function receive(IntegrationConnection $connection, Request $request): ?IntegrationEvent
    {
        $provider = $this->driverFor($connection);

        if (! $provider->verify($connection, $request)) {
            throw new RuntimeException('The signature does not match.', 401);
        }

        if (! $connection->isOpen()) {
            throw new RuntimeException('The connection is paused.', 503);
        }

        $eventId = $provider->eventId($request) ?? hash('sha256', $request->getContent());

        try {
            $event = IntegrationEvent::query()->create([
                'integration_connection_id' => $connection->getKey(),
                'provider_event_id' => $eventId,
                'type' => $provider->eventType($request),
                'payload' => (array) $request->json()->all(),
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Redelivered. Held already — processed, failed, or queued. A queued one older than a
            // couple of minutes is one whose dispatch did not survive the first request, and the
            // redelivery is the chance to queue it again rather than acknowledge it into limbo.
            $this->requeueIfStranded($connection, $eventId);

            return null;
        }

        $connection->forceFill(['last_event_at' => now()])->save();

        try {
            ProcessIntegrationEvent::dispatch($event->getKey());
        } catch (Throwable) {
            // Only the sync queue runs the job here, and process() has already written the failure on
            // the event. The provider gets its 202 either way: a redelivery would carry the same payload.
        }

        return $event;
    }

    private function requeueIfStranded(IntegrationConnection $connection, string $eventId): void
    {
        $held = IntegrationEvent::query()
            ->where('integration_connection_id', $connection->getKey())
            ->where('provider_event_id', $eventId)
            ->pending()
            ->where('received_at', '<', now()->subMinutes(2))
            ->first();

        if ($held !== null) {
            ProcessIntegrationEvent::dispatch($held->getKey());
        }
    }

    /**
     * Hold a pulled entry as an event, so the pull and the webhook are one path — §8.8.
     *
     * @param  array<string, mixed>  $payload
     * @return IntegrationEvent|null null when this exact state of the entry is already held
     */
    public function hold(IntegrationConnection $connection, string $eventId, string $type, array $payload): ?IntegrationEvent
    {
        try {
            $event = IntegrationEvent::query()->create([
                'integration_connection_id' => $connection->getKey(),
                'provider_event_id' => $eventId,
                'type' => $type,
                'payload' => $payload,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        ProcessIntegrationEvent::dispatch($event->getKey());

        return $event;
    }

    /** Turn one stored delivery into what it says, once. */
    public function process(IntegrationEvent $event): void
    {
        if ($event->isProcessed()) {
            return;
        }

        $connection = $event->connection;

        if ($connection === null) {
            $event->forceFill(['failed_at' => now(), 'failure' => 'The connection is gone.'])->save();

            return;
        }

        try {
            $provider = $this->driverFor($connection);

            if ($provider instanceof TimeProvider) {
                $this->time->handle($connection, $provider, $provider->entries($event));
            } else {
                $this->facts->handle($connection, $provider->facts($event));
            }

            $event->forceFill(['processed_at' => now(), 'failed_at' => null, 'failure' => null])->save();
        } catch (Throwable $e) {
            $event->forceFill(['failed_at' => now(), 'failure' => mb_substr($e->getMessage(), 0, 2000)])->save();

            throw $e;
        }
    }

    /** Try a failed delivery again — from the connection's page, once whatever failed is fixed. */
    public function replay(IntegrationEvent $event): void
    {
        $event->forceFill(['processed_at' => null, 'failed_at' => null, 'failure' => null])->save();

        ProcessIntegrationEvent::dispatch($event->getKey());
    }

    /** @throws RuntimeException */
    public function providerFor(IntegrationConnection $connection): CodeProvider
    {
        $driver = $this->driverFor($connection);

        if (! $driver instanceof CodeProvider) {
            throw new RuntimeException('"'.$connection->provider.'" is not a code host.', 404);
        }

        return $driver;
    }

    /** @throws RuntimeException */
    public function timeProviderFor(IntegrationConnection $connection): TimeProvider
    {
        $driver = $this->driverFor($connection);

        if (! $driver instanceof TimeProvider) {
            throw new RuntimeException('"'.$connection->provider.'" is not a time tracker.', 404);
        }

        return $driver;
    }

    /** @throws RuntimeException */
    public function driverFor(IntegrationConnection $connection): CodeProvider|TimeProvider
    {
        $driver = Integrations::driver($connection->provider);

        if (! $driver instanceof CodeProvider && ! $driver instanceof TimeProvider) {
            throw new RuntimeException('No provider is registered as "'.$connection->provider.'".', 404);
        }

        return $driver;
    }
}
