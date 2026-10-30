<?php

namespace App\Modules\Work\Services;

use App\Modules\Work\Integrations\CodeProvider;
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
 */
class IntegrationEvents
{
    public function __construct(private readonly FactHandler $facts) {}

    /**
     * Accept a delivery, or refuse it.
     *
     * @return IntegrationEvent|null the stored event; null for a redelivery already held
     *
     * @throws RuntimeException with the HTTP status to answer, as the code
     */
    public function receive(IntegrationConnection $connection, Request $request): ?IntegrationEvent
    {
        $provider = $this->providerFor($connection);

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
            // Redelivered. Held already, processed or about to be; nothing more to do.
            return null;
        }

        $connection->forceFill(['last_event_at' => now()])->save();

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
            $provider = $this->providerFor($connection);
            $this->facts->handle($connection, $provider->facts($event));

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
        $driver = Integrations::driver($connection->provider);

        if (! $driver instanceof CodeProvider) {
            throw new RuntimeException('No code provider is registered as "'.$connection->provider.'".', 404);
        }

        return $driver;
    }
}
