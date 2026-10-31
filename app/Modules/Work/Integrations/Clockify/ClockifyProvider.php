<?php

namespace App\Modules\Work\Integrations\Clockify;

use App\Modules\Work\Integrations\Facts\TimeEntryFact;
use App\Modules\Work\Integrations\RestClient;
use App\Modules\Work\Integrations\TimeProvider;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\IntegrationEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Clockify — docs/project-management-plan.md §8.8.
 *
 * A workspace is a connection, authenticated by the workspace API key the company's Clockify
 * admin generates (the one credential Clockify offers at that level), stored encrypted beside
 * the workspace id. Clockify signs nothing: each webhook carries a static signing token it issued
 * as `Clockify-Signature`, compared in constant time against the connection's secret, which is
 * what the administrator pastes into Clockify when making the webhook.
 *
 * **Rates are never read.** `hourlyRate` is on every entry Clockify sends and this class does not
 * look at it: the rate is this application's (§9), and two sources of a rate is a reconciliation
 * nobody runs.
 */
class ClockifyProvider implements TimeProvider
{
    public const KEY = 'clockify';

    public function key(): string
    {
        return self::KEY;
    }

    public function verify(IntegrationConnection $connection, Request $request): bool
    {
        $given = (string) $request->header('Clockify-Signature', '');

        return $given !== '' && hash_equals($connection->webhook_secret, $given);
    }

    public function eventId(Request $request): ?string
    {
        // Clockify sends no delivery id. The entry id plus the event type plus the moment the
        // entry was last touched is what distinguishes one delivery from the next about the same
        // entry — and makes the same one delivered three times one event.
        $entry = (array) $request->json()->all();
        $id = $entry['id'] ?? null;

        if (blank($id)) {
            return null;
        }

        return $id.':'.$this->eventType($request).':'.md5((string) json_encode($entry['timeInterval'] ?? []).(string) ($entry['description'] ?? ''));
    }

    public function eventType(Request $request): string
    {
        return (string) $request->header('Clockify-Webhook-Event-Type', 'TIME_ENTRY');
    }

    public function entries(IntegrationEvent $event): array
    {
        $payload = $event->payload ?? [];

        if (blank($payload['id'] ?? null)) {
            return [];
        }

        return [$this->fact($payload, deleted: $event->type === 'TIME_ENTRY_DELETED')];
    }

    /** @param  array<string, mixed>  $entry  Clockify's time entry shape */
    public function fact(array $entry, bool $deleted = false): TimeEntryFact
    {
        $interval = (array) ($entry['timeInterval'] ?? []);

        return new TimeEntryFact(
            externalId: (string) $entry['id'],
            externalUserId: (string) ($entry['userId'] ?? ($entry['user']['id'] ?? '')),
            externalProjectId: isset($entry['projectId']) ? (string) $entry['projectId'] : (isset($entry['project']['id']) ? (string) $entry['project']['id'] : null),
            externalTaskId: isset($entry['taskId']) ? (string) $entry['taskId'] : (isset($entry['task']['id']) ? (string) $entry['task']['id'] : null),
            description: $entry['description'] ?? null,
            start: Carbon::parse($interval['start'] ?? now()),
            end: filled($interval['end'] ?? null) ? Carbon::parse($interval['end']) : null,
            billable: (bool) ($entry['billable'] ?? false),
            deleted: $deleted,
            userEmail: $entry['user']['email'] ?? null,
            userName: $entry['user']['name'] ?? null,
        );
    }

    private function client(IntegrationConnection $connection): RestClient
    {
        return new RestClient($connection, $connection->base_url ?: 'https://api.clockify.me/api/v1',
            fn (PendingRequest $http): PendingRequest => $http->withHeaders(['X-Api-Key' => (string) $connection->credential('api_key')]));
    }

    private function workspace(IntegrationConnection $connection): string
    {
        return (string) ($connection->credential('workspace_id') ?: $connection->external_account_id);
    }

    public function pull(IntegrationConnection $connection, Carbon $since): array
    {
        $client = $this->client($connection);
        $workspace = $this->workspace($connection);
        $facts = [];

        // Clockify lists entries per user, so the pull is one call per workspace member.
        foreach ($this->users($connection) as $userId => $user) {
            $page = 1;

            do {
                $rows = $client->get('/workspaces/'.$workspace.'/user/'.$userId.'/time-entries', [
                    'start' => $since->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'page-size' => 200,
                    'page' => $page,
                ]);

                foreach ($rows as $entry) {
                    if (is_array($entry) && filled($entry['id'] ?? null)) {
                        $entry['userId'] ??= (string) $userId;
                        $facts[] = $this->fact($entry);
                    }
                }

                $page++;
            } while (count($rows) === 200 && $page <= 20);
        }

        return $facts;
    }

    public function projects(IntegrationConnection $connection): array
    {
        $rows = $this->client($connection)->get('/workspaces/'.$this->workspace($connection).'/projects', ['page-size' => 200, 'archived' => 'false']);

        return array_values(array_map(fn (array $project): array => [
            'id' => (string) $project['id'],
            'name' => (string) $project['name'],
        ], array_filter($rows, 'is_array')));
    }

    public function createProject(IntegrationConnection $connection, string $name, ?string $code = null): array
    {
        $made = $this->client($connection)->post('/workspaces/'.$this->workspace($connection).'/projects', array_filter([
            'name' => $name,
            'note' => $code,
            'isPublic' => true,
        ]));

        return ['id' => (string) ($made['id'] ?? ''), 'name' => (string) ($made['name'] ?? $name)];
    }

    public function createTask(IntegrationConnection $connection, string $externalProjectId, string $name): array
    {
        $made = $this->client($connection)->post('/workspaces/'.$this->workspace($connection).'/projects/'.$externalProjectId.'/tasks', [
            'name' => $name,
            'status' => 'ACTIVE',
        ]);

        return ['id' => (string) ($made['id'] ?? ''), 'name' => (string) ($made['name'] ?? $name)];
    }

    public function users(IntegrationConnection $connection): array
    {
        $key = 'work.clockify.users.'.$connection->getKey();

        return Cache::remember($key, now()->addHour(), function () use ($connection): array {
            $rows = $this->client($connection)->get('/workspaces/'.$this->workspace($connection).'/users', ['page-size' => 200, 'status' => 'ACTIVE']);
            $users = [];

            foreach (array_filter($rows, 'is_array') as $user) {
                if (filled($user['id'] ?? null)) {
                    $users[(string) $user['id']] = ['email' => $user['email'] ?? null, 'name' => $user['name'] ?? null];
                }
            }

            return $users;
        });
    }

    public function startTimer(IntegrationConnection $connection, string $externalUserId, string $externalProjectId, ?string $externalTaskId, string $description, bool $billable): array
    {
        $made = $this->client($connection)->post('/workspaces/'.$this->workspace($connection).'/user/'.$externalUserId.'/time-entries', array_filter([
            'start' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'projectId' => $externalProjectId,
            'taskId' => $externalTaskId,
            'description' => $description,
            'billable' => $billable,
        ], fn ($value): bool => $value !== null));

        return ['id' => (string) ($made['id'] ?? '')];
    }

    public function stopTimer(IntegrationConnection $connection, string $externalUserId): ?TimeEntryFact
    {
        $stopped = $this->client($connection)->patch('/workspaces/'.$this->workspace($connection).'/user/'.$externalUserId.'/time-entries', [
            'end' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);

        if (blank($stopped['id'] ?? null)) {
            return null;
        }

        $stopped['userId'] ??= $externalUserId;

        return $this->fact($stopped);
    }

    public function runningTimer(IntegrationConnection $connection, string $externalUserId): ?TimeEntryFact
    {
        $rows = $this->client($connection)->get('/workspaces/'.$this->workspace($connection).'/user/'.$externalUserId.'/time-entries', [
            'in-progress' => 'true',
        ]);

        $running = collect($rows)->filter('is_array')->first();

        if ($running === null || blank($running['id'] ?? null)) {
            return null;
        }

        $running['userId'] ??= $externalUserId;

        return $this->fact($running);
    }
}
