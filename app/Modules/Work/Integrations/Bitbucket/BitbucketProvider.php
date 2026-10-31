<?php

namespace App\Modules\Work\Integrations\Bitbucket;

use App\Modules\Work\Integrations\CodeProvider;
use App\Modules\Work\Integrations\Facts\BranchFact;
use App\Modules\Work\Integrations\Facts\CommitFact;
use App\Modules\Work\Integrations\Facts\PullRequestFact;
use App\Modules\Work\Integrations\RestClient;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\IntegrationEvent;
use App\Modules\Work\Models\WorkRepository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Bitbucket Cloud — docs/project-management-plan.md §8.2, §8.3, Phase 8.
 *
 * Parsing and auth; the facts and everything after them are already provider-free. The webhook
 * secret is carried as `X-Hub-Signature` (an HMAC of the body), which Bitbucket Cloud has signed
 * with since 2023; a delivery without one is refused, because an unsigned hook is a URL anybody
 * who finds it can post to. Bitbucket Server/Data Center is a different API and is deferred
 * (§16.5).
 *
 * Authenticates with an **app password** (username + password, Basic) or a workspace **access
 * token** (Bearer). The OAuth 2 consumer flow the plan names is the one piece not built here: it
 * needs a redirect round-trip through the browser, and both token forms work for every company
 * that has asked so far.
 */
class BitbucketProvider implements CodeProvider
{
    public const KEY = 'bitbucket';

    public function key(): string
    {
        return self::KEY;
    }

    public function verify(IntegrationConnection $connection, Request $request): bool
    {
        $given = (string) $request->header('X-Hub-Signature', '');

        if (! str_starts_with($given, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $connection->webhook_secret), $given);
    }

    public function eventId(Request $request): ?string
    {
        $id = $request->header('X-Request-UUID');

        return filled($id) ? trim((string) $id, '{}') : null;
    }

    public function eventType(Request $request): string
    {
        return (string) $request->header('X-Event-Key', 'unknown');
    }

    public function facts(IntegrationEvent $event): array
    {
        $payload = $event->payload ?? [];
        $repository = trim((string) ($payload['repository']['uuid'] ?? ''), '{}');
        $actor = $this->author($payload['actor'] ?? []);

        return match (true) {
            $event->type === 'repo:push' => $this->pushFacts($repository, $payload, $actor),
            str_starts_with($event->type, 'pullrequest:') => [$this->pullRequestFact($repository, $payload, $event->type)],
            default => [],
        };
    }

    /** @return array<int, CommitFact|BranchFact> */
    private function pushFacts(string $repository, array $payload, array $actor): array
    {
        $facts = [];
        $html = $payload['repository']['links']['html']['href'] ?? null;

        foreach ((array) ($payload['push']['changes'] ?? []) as $change) {
            $new = $change['new'] ?? null;
            $old = $change['old'] ?? null;

            if (($new['type'] ?? $old['type'] ?? null) === 'branch') {
                $name = (string) ($new['name'] ?? $old['name'] ?? '');

                if (($change['created'] ?? false) || ($change['closed'] ?? false)) {
                    $facts[] = new BranchFact($repository, $actor, $name, deleted: (bool) ($change['closed'] ?? false),
                        url: $html !== null && ! ($change['closed'] ?? false) ? $html.'/branch/'.$name : null);
                }
            }

            foreach ((array) ($change['commits'] ?? []) as $commit) {
                if (blank($commit['hash'] ?? null)) {
                    continue;
                }

                $raw = (string) ($commit['author']['raw'] ?? '');
                preg_match('/<([^>]+)>/', $raw, $email);

                $facts[] = new CommitFact(
                    $repository,
                    [
                        'username' => $commit['author']['user']['nickname'] ?? ($commit['author']['user']['display_name'] ?? ($actor['username'] ?? null)),
                        'id' => isset($commit['author']['user']['uuid']) ? trim((string) $commit['author']['user']['uuid'], '{}') : null,
                        'email' => $email[1] ?? null,
                    ],
                    (string) $commit['hash'],
                    (string) ($commit['message'] ?? ''),
                    isset($commit['date']) ? Carbon::parse($commit['date']) : null,
                    $commit['links']['html']['href'] ?? null,
                );
            }
        }

        return $facts;
    }

    private function pullRequestFact(string $repository, array $payload, string $type): PullRequestFact
    {
        $pr = (array) ($payload['pullrequest'] ?? []);

        $action = match ($type) {
            'pullrequest:created' => PullRequestFact::OPENED,
            'pullrequest:fulfilled' => PullRequestFact::MERGED,
            'pullrequest:rejected' => PullRequestFact::CLOSED,
            default => PullRequestFact::UPDATED,
        };

        return new PullRequestFact(
            $repository,
            $this->author($pr['author'] ?? ($payload['actor'] ?? [])),
            (int) ($pr['id'] ?? 0),
            (string) ($pr['title'] ?? ''),
            (string) ($pr['source']['branch']['name'] ?? ''),
            $action,
            $pr['links']['html']['href'] ?? null,
            $action === PullRequestFact::MERGED ? Carbon::parse($pr['updated_on'] ?? now()) : null,
        );
    }

    /** @return array{username: ?string, id: ?string, email: ?string} */
    private function author(array $user): array
    {
        return [
            'username' => $user['nickname'] ?? ($user['display_name'] ?? null),
            'id' => isset($user['uuid']) ? trim((string) $user['uuid'], '{}') : null,
            'email' => null,
        ];
    }

    private function client(IntegrationConnection $connection): RestClient
    {
        return new RestClient($connection, $connection->base_url ?: 'https://api.bitbucket.org/2.0', function (PendingRequest $http) use ($connection): PendingRequest {
            $username = $connection->credential('username');
            $token = (string) $connection->credential('token');

            // An app password travels as Basic with the username; a workspace access token as Bearer.
            return filled($username) ? $http->withBasicAuth($username, $token) : $http->withToken($token);
        });
    }

    public function repositories(IntegrationConnection $connection): array
    {
        $workspace = $connection->credential('workspace') ?: $connection->external_account_id;
        $page = $this->client($connection)->get('/repositories/'.$workspace, ['pagelen' => 100, 'sort' => '-updated_on']);

        return array_values(array_map(fn (array $repo): array => [
            'id' => trim((string) $repo['uuid'], '{}'),
            'full_name' => (string) $repo['full_name'],
            'default_branch' => (string) ($repo['mainbranch']['name'] ?? 'main'),
            'url' => (string) ($repo['links']['html']['href'] ?? ''),
        ], (array) ($page['values'] ?? [])));
    }

    public function registerWebhook(WorkRepository $repository, string $url, string $secret): ?string
    {
        $created = $this->client($repository->connection)->post('/repositories/'.$repository->full_name.'/hooks', [
            'description' => 'Mizan work items',
            'url' => $url,
            'active' => true,
            'secret' => $secret,
            'events' => ['repo:push', 'pullrequest:created', 'pullrequest:updated', 'pullrequest:fulfilled', 'pullrequest:rejected'],
        ]);

        return isset($created['uuid']) ? trim((string) $created['uuid'], '{}') : null;
    }

    public function removeWebhook(WorkRepository $repository): void
    {
        if (filled($repository->webhook_id)) {
            $this->client($repository->connection)->delete('/repositories/'.$repository->full_name.'/hooks/{'.$repository->webhook_id.'}');
        }
    }

    public function createBranch(WorkRepository $repository, string $name, ?string $from = null): array
    {
        $this->client($repository->connection)->post('/repositories/'.$repository->full_name.'/refs/branches', [
            'name' => $name,
            'target' => ['hash' => $from ?: $repository->default_branch],
        ]);

        return ['name' => $name, 'url' => ($repository->url ?: 'https://bitbucket.org/'.$repository->full_name).'/branch/'.$name];
    }

    public function openPullRequest(WorkRepository $repository, string $head, string $base, string $title, string $body): array
    {
        $pr = $this->client($repository->connection)->post('/repositories/'.$repository->full_name.'/pullrequests', [
            'title' => $title,
            'description' => $body,
            'source' => ['branch' => ['name' => $head]],
            'destination' => ['branch' => ['name' => $base]],
        ]);

        return ['number' => (int) ($pr['id'] ?? 0), 'url' => $pr['links']['html']['href'] ?? null, 'title' => (string) ($pr['title'] ?? $title)];
    }

    public function createRelease(WorkRepository $repository, string $tag, string $name, string $body): array
    {
        // Bitbucket has no releases; a tag on the default branch with the notes as its message is the
        // nearest thing it offers, and it is what its own users do.
        $made = $this->client($repository->connection)->post('/repositories/'.$repository->full_name.'/refs/tags', [
            'name' => $tag,
            'target' => ['hash' => $repository->default_branch],
            'message' => $name."\n\n".$body,
        ]);

        return ['id' => (string) ($made['name'] ?? $tag), 'url' => $made['links']['html']['href'] ?? null];
    }

    public function parsePullRequestUrl(string $url): ?array
    {
        if (preg_match('#bitbucket\.org/([^/]+/[^/]+)/pull-requests/(\d+)#i', $url, $m)) {
            return [$m[1], (int) $m[2]];
        }

        return null;
    }
}
