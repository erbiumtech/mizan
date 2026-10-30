<?php

namespace App\Modules\Work\Integrations\GitLab;

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
 * GitLab — docs/project-management-plan.md §8.2, §8.3, Phase 8.
 *
 * Self-hosted is the common case, so the instance URL is on the connection. The webhook carries
 * no signature: GitLab sends the secret back verbatim as `X-Gitlab-Token`, compared in constant
 * time. A merge request is a pull request here, and the facts do not know the difference.
 *
 * Authenticates with a personal or project access token (`PRIVATE-TOKEN`). The OAuth application
 * flow the plan names is deferred on the same grounds as Bitbucket's.
 */
class GitLabProvider implements CodeProvider
{
    public const KEY = 'gitlab';

    public function key(): string
    {
        return self::KEY;
    }

    public function verify(IntegrationConnection $connection, Request $request): bool
    {
        $given = (string) $request->header('X-Gitlab-Token', '');

        return $given !== '' && hash_equals($connection->webhook_secret, $given);
    }

    public function eventId(Request $request): ?string
    {
        $id = $request->header('X-Gitlab-Event-UUID');

        return filled($id) ? (string) $id : null;
    }

    public function eventType(Request $request): string
    {
        return (string) $request->header('X-Gitlab-Event', 'unknown');
    }

    public function facts(IntegrationEvent $event): array
    {
        $payload = $event->payload ?? [];
        $repository = (string) ($payload['project']['id'] ?? $payload['project_id'] ?? '');
        $kind = (string) ($payload['object_kind'] ?? '');

        return match ($kind) {
            'push' => $this->pushFacts($repository, $payload),
            'merge_request' => [$this->mergeRequestFact($repository, $payload)],
            default => [],
        };
    }

    /** @return array<int, CommitFact|BranchFact> */
    private function pushFacts(string $repository, array $payload): array
    {
        $facts = [];
        $ref = (string) ($payload['ref'] ?? '');
        $actor = [
            'username' => $payload['user_username'] ?? null,
            'id' => isset($payload['user_id']) ? (string) $payload['user_id'] : null,
            'email' => $payload['user_email'] ?? null,
            // GitLab names the authenticated pusher here, not the commit's author line.
            'verified' => filled($payload['user_email'] ?? null),
        ];
        $zeros = str_repeat('0', 40);

        if (str_starts_with($ref, 'refs/heads/')) {
            $name = substr($ref, strlen('refs/heads/'));
            $created = ($payload['before'] ?? '') === $zeros;
            $deleted = ($payload['after'] ?? '') === $zeros;

            if ($created || $deleted) {
                $facts[] = new BranchFact($repository, $actor, $name, deleted: $deleted,
                    url: ! $deleted && isset($payload['project']['web_url']) ? $payload['project']['web_url'].'/-/tree/'.$name : null);
            }
        }

        foreach ((array) ($payload['commits'] ?? []) as $commit) {
            if (blank($commit['id'] ?? null)) {
                continue;
            }

            $facts[] = new CommitFact(
                $repository,
                [
                    'username' => $actor['username'] ?? ($commit['author']['name'] ?? null),
                    'id' => $actor['id'],
                    // The pusher's own address when GitLab gave it; the author line otherwise, unvouched.
                    'email' => $actor['email'] ?? ($commit['author']['email'] ?? null),
                    'verified' => $actor['verified'],
                ],
                (string) $commit['id'],
                (string) ($commit['message'] ?? ''),
                isset($commit['timestamp']) ? Carbon::parse($commit['timestamp']) : null,
                $commit['url'] ?? null,
            );
        }

        return $facts;
    }

    private function mergeRequestFact(string $repository, array $payload): PullRequestFact
    {
        $mr = (array) ($payload['object_attributes'] ?? []);
        $action = (string) ($mr['action'] ?? 'update');

        $ours = match ($action) {
            'open', 'reopen' => PullRequestFact::OPENED,
            'merge' => PullRequestFact::MERGED,
            'close' => PullRequestFact::CLOSED,
            default => PullRequestFact::UPDATED,
        };

        return new PullRequestFact(
            $repository,
            [
                'username' => $payload['user']['username'] ?? null,
                'id' => isset($payload['user']['id']) ? (string) $payload['user']['id'] : null,
                'email' => $payload['user']['email'] ?? null,
                'verified' => filled($payload['user']['email'] ?? null),
            ],
            (int) ($mr['iid'] ?? 0),
            (string) ($mr['title'] ?? ''),
            (string) ($mr['source_branch'] ?? ''),
            $ours,
            $mr['url'] ?? null,
            ! empty($mr['merged_at']) ? Carbon::parse($mr['merged_at']) : ($ours === PullRequestFact::MERGED ? now() : null),
        );
    }

    private function client(IntegrationConnection $connection): RestClient
    {
        return new RestClient($connection, rtrim($connection->base_url ?: 'https://gitlab.com', '/').'/api/v4',
            fn (PendingRequest $http): PendingRequest => $http->withHeaders(['PRIVATE-TOKEN' => (string) $connection->credential('token')]));
    }

    private function path(WorkRepository $repository): string
    {
        return '/projects/'.rawurlencode($repository->external_id ?: $repository->full_name);
    }

    public function repositories(IntegrationConnection $connection): array
    {
        $rows = $this->client($connection)->get('/projects', ['membership' => 'true', 'per_page' => 100, 'order_by' => 'last_activity_at', 'simple' => 'true']);

        return array_values(array_map(fn (array $project): array => [
            'id' => (string) $project['id'],
            'full_name' => (string) ($project['path_with_namespace'] ?? $project['name']),
            'default_branch' => (string) ($project['default_branch'] ?? 'main'),
            'url' => (string) ($project['web_url'] ?? ''),
        ], $rows));
    }

    public function registerWebhook(WorkRepository $repository, string $url, string $secret): ?string
    {
        $created = $this->client($repository->connection)->post($this->path($repository).'/hooks', [
            'url' => $url,
            'token' => $secret,
            'push_events' => true,
            'merge_requests_events' => true,
            'enable_ssl_verification' => true,
        ]);

        return isset($created['id']) ? (string) $created['id'] : null;
    }

    public function removeWebhook(WorkRepository $repository): void
    {
        if (filled($repository->webhook_id)) {
            $this->client($repository->connection)->delete($this->path($repository).'/hooks/'.$repository->webhook_id);
        }
    }

    public function createBranch(WorkRepository $repository, string $name, ?string $from = null): array
    {
        $made = $this->client($repository->connection)->post($this->path($repository).'/repository/branches', [
            'branch' => $name,
            'ref' => $from ?: $repository->default_branch,
        ]);

        return ['name' => $name, 'url' => $made['web_url'] ?? (($repository->url ?: '').'/-/tree/'.$name)];
    }

    public function openPullRequest(WorkRepository $repository, string $head, string $base, string $title, string $body): array
    {
        $mr = $this->client($repository->connection)->post($this->path($repository).'/merge_requests', [
            'source_branch' => $head,
            'target_branch' => $base,
            'title' => $title,
            'description' => $body,
        ]);

        return ['number' => (int) ($mr['iid'] ?? 0), 'url' => $mr['web_url'] ?? null, 'title' => (string) ($mr['title'] ?? $title)];
    }

    public function createRelease(WorkRepository $repository, string $tag, string $name, string $body): array
    {
        $release = $this->client($repository->connection)->post($this->path($repository).'/releases', [
            'tag_name' => $tag,
            'ref' => $repository->default_branch,
            'name' => $name,
            'description' => $body,
        ]);

        return ['id' => (string) ($release['tag_name'] ?? $tag), 'url' => $release['_links']['self'] ?? null];
    }

    public function parsePullRequestUrl(string $url): ?array
    {
        $path = parse_url($url, PHP_URL_PATH) ?? $url;

        if (is_string($path) && preg_match('#^/?(.+?)/-/merge_requests/(\d+)#i', $path, $m)) {
            return [$m[1], (int) $m[2]];
        }

        return null;
    }
}
