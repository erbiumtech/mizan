<?php

namespace App\Modules\Work\Integrations\GitHub;

use App\Modules\Work\Integrations\CodeProvider;
use App\Modules\Work\Integrations\Facts\BranchFact;
use App\Modules\Work\Integrations\Facts\CommitFact;
use App\Modules\Work\Integrations\Facts\PullRequestFact;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\IntegrationEvent;
use App\Modules\Work\Models\WorkRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GitHub — docs/project-management-plan.md §8.2–§8.6, for one provider.
 *
 * First because it is where most of this company's own code lives and the one every candidate
 * will have used. The parsing is the only part that knows GitHub's shapes; what comes out is the
 * three facts, and everything downstream is provider-free.
 */
class GitHubProvider implements CodeProvider
{
    public const KEY = 'github';

    public function key(): string
    {
        return self::KEY;
    }

    /** `X-Hub-Signature-256`, an HMAC of the raw body with the connection's secret — §8.3. */
    public function verify(IntegrationConnection $connection, Request $request): bool
    {
        $given = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($given, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $connection->webhook_secret);

        return hash_equals($expected, $given);
    }

    public function eventId(Request $request): ?string
    {
        $id = $request->header('X-GitHub-Delivery');

        return filled($id) ? (string) $id : null;
    }

    public function eventType(Request $request): string
    {
        return (string) $request->header('X-GitHub-Event', 'unknown');
    }

    public function facts(IntegrationEvent $event): array
    {
        $payload = $event->payload ?? [];
        $repository = (string) ($payload['repository']['id'] ?? '');

        return match ($event->type) {
            'push' => $this->pushFacts($repository, $payload),
            'create' => [$this->branchFact($repository, $payload, deleted: false)],
            'delete' => [$this->branchFact($repository, $payload, deleted: true)],
            'pull_request' => [$this->pullRequestFact($repository, $payload)],
            default => [],
        };
    }

    /** @return array<int, CommitFact|BranchFact> */
    private function pushFacts(string $repository, array $payload): array
    {
        $facts = [];
        $ref = (string) ($payload['ref'] ?? '');

        // A push that creates or deletes a branch also arrives as `create`/`delete`; the flags
        // here are for a provider that sends only `push`, and are read when those are absent.
        if (str_starts_with($ref, 'refs/heads/') && (($payload['created'] ?? false) || ($payload['deleted'] ?? false))) {
            $facts[] = new BranchFact(
                $repository,
                $this->author($payload['sender'] ?? [], $payload['pusher'] ?? []),
                substr($ref, strlen('refs/heads/')),
                deleted: (bool) ($payload['deleted'] ?? false),
                url: ($payload['repository']['html_url'] ?? null) !== null
                    ? $payload['repository']['html_url'].'/tree/'.substr($ref, strlen('refs/heads/'))
                    : null,
            );
        }

        foreach ((array) ($payload['commits'] ?? []) as $commit) {
            if (blank($commit['id'] ?? null)) {
                continue;
            }

            $facts[] = new CommitFact(
                $repository,
                [
                    'username' => $commit['author']['username'] ?? ($payload['sender']['login'] ?? null),
                    'id' => isset($payload['sender']['id']) ? (string) $payload['sender']['id'] : null,
                    'email' => $commit['author']['email'] ?? null,
                ],
                (string) $commit['id'],
                (string) ($commit['message'] ?? ''),
                isset($commit['timestamp']) ? Carbon::parse($commit['timestamp']) : null,
                $commit['url'] ?? null,
            );
        }

        return $facts;
    }

    private function branchFact(string $repository, array $payload, bool $deleted): BranchFact
    {
        $name = (string) ($payload['ref'] ?? '');

        return new BranchFact(
            $repository,
            $this->author($payload['sender'] ?? []),
            $name,
            deleted: $deleted,
            url: ! $deleted && isset($payload['repository']['html_url']) ? $payload['repository']['html_url'].'/tree/'.$name : null,
        );
    }

    private function pullRequestFact(string $repository, array $payload): PullRequestFact
    {
        $pr = (array) ($payload['pull_request'] ?? []);
        $action = (string) ($payload['action'] ?? 'edited');

        $ours = match (true) {
            $action === 'opened', $action === 'reopened', $action === 'ready_for_review' => PullRequestFact::OPENED,
            $action === 'closed' && ! empty($pr['merged_at']) => PullRequestFact::MERGED,
            $action === 'closed' => PullRequestFact::CLOSED,
            default => PullRequestFact::UPDATED,
        };

        return new PullRequestFact(
            $repository,
            $this->author($pr['user'] ?? ($payload['sender'] ?? [])),
            (int) ($pr['number'] ?? $payload['number'] ?? 0),
            (string) ($pr['title'] ?? ''),
            (string) ($pr['head']['ref'] ?? ''),
            $ours,
            $pr['html_url'] ?? null,
            ! empty($pr['merged_at']) ? Carbon::parse($pr['merged_at']) : null,
        );
    }

    /** @return array{username: ?string, id: ?string, email: ?string} */
    private function author(array $sender, array $pusher = []): array
    {
        return [
            'username' => $sender['login'] ?? ($pusher['name'] ?? null),
            'id' => isset($sender['id']) ? (string) $sender['id'] : null,
            'email' => $sender['email'] ?? ($pusher['email'] ?? null),
        ];
    }

    public function repositories(IntegrationConnection $connection): array
    {
        $client = new GitHubClient($connection);

        $rows = $connection->auth_kind === IntegrationConnection::AUTH_APP
            ? (array) ($client->get('/installation/repositories', ['per_page' => 100])['repositories'] ?? [])
            : $client->get('/user/repos', ['per_page' => 100, 'sort' => 'updated']);

        return array_values(array_map(fn (array $repo): array => [
            'id' => (string) $repo['id'],
            'full_name' => (string) $repo['full_name'],
            'default_branch' => (string) ($repo['default_branch'] ?? 'main'),
            'url' => (string) ($repo['html_url'] ?? ''),
        ], $rows));
    }

    public function registerWebhook(WorkRepository $repository, string $url, string $secret): ?string
    {
        $created = (new GitHubClient($repository->connection))->post('/repos/'.$repository->full_name.'/hooks', [
            'name' => 'web',
            'active' => true,
            'events' => ['push', 'create', 'delete', 'pull_request'],
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => $secret, 'insecure_ssl' => '0'],
        ]);

        return isset($created['id']) ? (string) $created['id'] : null;
    }

    public function removeWebhook(WorkRepository $repository): void
    {
        if (blank($repository->webhook_id)) {
            return;
        }

        (new GitHubClient($repository->connection))->delete('/repos/'.$repository->full_name.'/hooks/'.$repository->webhook_id);
    }

    public function createBranch(WorkRepository $repository, string $name, ?string $from = null): array
    {
        $client = new GitHubClient($repository->connection);
        $base = $from ?: $repository->default_branch;

        $ref = $client->get('/repos/'.$repository->full_name.'/git/ref/heads/'.$base);
        $sha = (string) ($ref['object']['sha'] ?? '');

        if ($sha === '') {
            throw new \RuntimeException('The base branch '.$base.' was not found on '.$repository->full_name.'.');
        }

        $client->post('/repos/'.$repository->full_name.'/git/refs', ['ref' => 'refs/heads/'.$name, 'sha' => $sha]);

        return ['name' => $name, 'url' => ($repository->url ?: 'https://github.com/'.$repository->full_name).'/tree/'.$name];
    }

    public function openPullRequest(WorkRepository $repository, string $head, string $base, string $title, string $body): array
    {
        $pr = (new GitHubClient($repository->connection))->post('/repos/'.$repository->full_name.'/pulls', [
            'title' => $title,
            'head' => $head,
            'base' => $base,
            'body' => $body,
        ]);

        return ['number' => (int) ($pr['number'] ?? 0), 'url' => $pr['html_url'] ?? null, 'title' => (string) ($pr['title'] ?? $title)];
    }

    public function createRelease(WorkRepository $repository, string $tag, string $name, string $body): array
    {
        $release = (new GitHubClient($repository->connection))->post('/repos/'.$repository->full_name.'/releases', [
            'tag_name' => $tag,
            'name' => $name,
            'body' => $body,
            'draft' => false,
        ]);

        return ['id' => (string) ($release['id'] ?? ''), 'url' => $release['html_url'] ?? null];
    }

    public function parsePullRequestUrl(string $url): ?array
    {
        if (preg_match('#github\.com/([^/]+/[^/]+)/pull/(\d+)#i', $url, $m)) {
            return [$m[1], (int) $m[2]];
        }

        return null;
    }
}
