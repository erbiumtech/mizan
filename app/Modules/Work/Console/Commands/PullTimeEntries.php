<?php

namespace App\Modules\Work\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Work\Integrations\Facts\TimeEntryFact;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Services\IntegrationEvents;
use App\Support\Integrations;
use Illuminate\Console\Command;
use RuntimeException;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * The pull — docs/project-management-plan.md §8.8.
 *
 * Where the workspace's plan does not offer webhooks, or as the backstop where it does: every
 * fifteen minutes for the last two days, and nightly for the last thirty. Each pulled entry is
 * held as an event with an id made of the entry id and its current state, so an entry that has
 * not changed since the last pull is a duplicate and writes nothing, and one that has is a new
 * event that rewrites its row. Both paths land in `integration_events` and are processed by one
 * handler, which is the point.
 */
class PullTimeEntries extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'work:time-pull {--tenant=*} {--days=2 : How far back to ask}';

    protected $description = 'Pull time entries from every linked time tracker into the timesheet';

    public function handle(IntegrationEvents $events): int
    {
        if ($this->skipsDisabledModule('work') || $this->skipsDisabledModule('timesheets')) {
            return self::SUCCESS;
        }

        $since = now()->subDays(max(1, (int) $this->option('days')))->startOfDay();
        $held = 0;

        // Not `active()`: a connection the breaker paused stays `paused` on the row until a call
        // succeeds, and the pull is the only call a webhook-less workspace ever makes. `isOpen()`
        // is what knows the pause has expired.
        $connections = IntegrationConnection::query()
            ->where('kind', Integrations::TIME)
            ->where('status', '!=', IntegrationConnection::DISCONNECTED)
            ->get()
            ->filter(fn (IntegrationConnection $c): bool => $c->isOpen());

        foreach ($connections as $connection) {
            try {
                $provider = $events->timeProviderFor($connection);
                $facts = $provider->pull($connection, $since);
            } catch (RuntimeException $e) {
                $this->warn($connection->label.': '.$e->getMessage());

                continue;
            }

            foreach ($facts as $fact) {
                if ($events->hold($connection, $this->eventId($fact), 'PULL', $this->payload($fact)) !== null) {
                    $held++;
                }
            }

            $connection->forceFill(['last_event_at' => now()])->save();
        }

        $this->info("{$held} new or changed entries held.");

        return self::SUCCESS;
    }

    private function eventId(TimeEntryFact $fact): string
    {
        return $fact->externalId.':PULL:'.md5((string) json_encode([
            $fact->start->toIso8601String(), $fact->end?->toIso8601String(), $fact->description, $fact->billable, $fact->externalProjectId, $fact->externalTaskId,
        ]));
    }

    /** The provider's own shape, so the one handler reads a pulled entry exactly as a delivered one. */
    private function payload(TimeEntryFact $fact): array
    {
        return [
            'id' => $fact->externalId,
            'userId' => $fact->externalUserId,
            'projectId' => $fact->externalProjectId,
            'taskId' => $fact->externalTaskId,
            'description' => $fact->description,
            'billable' => $fact->billable,
            'timeInterval' => ['start' => $fact->start->toIso8601String(), 'end' => $fact->end?->toIso8601String()],
        ];
    }
}
