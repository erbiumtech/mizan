<?php

namespace App\Modules\Work\Jobs;

use App\Modules\Work\Models\IntegrationEvent;
use App\Modules\Work\Services\IntegrationEvents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One stored delivery, processed — docs/project-management-plan.md §8.3.
 *
 * Tenant-aware, which is the default here and is correct: the event row and everything it
 * touches are in the company's own database. The id, not the model, so a retry reads the event
 * as it stands then.
 *
 * Three attempts: a transient failure (the provider's API during a smart-commit time log, a lock)
 * is retried; a real one marks the event failed with the reason, and the connection's page offers
 * a replay once it is fixed.
 */
class ProcessIntegrationEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $eventId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 300];
    }

    public function handle(IntegrationEvents $events): void
    {
        $event = IntegrationEvent::query()->find($this->eventId);

        if ($event === null) {
            return;
        }

        $events->process($event);
    }
}
