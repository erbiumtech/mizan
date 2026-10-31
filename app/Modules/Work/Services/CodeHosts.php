<?php

namespace App\Modules\Work\Services;

use App\Modules\Core\Models\User;
use App\Modules\Work\Integrations\CodeProvider;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkItemBranch;
use App\Modules\Work\Models\WorkItemPullRequest;
use App\Modules\Work\Models\WorkRelease;
use App\Modules\Work\Models\WorkRepository;
use App\Modules\Work\Models\WorkStatus;
use App\Support\Integrations;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Outbound — docs/project-management-plan.md §8.1 (linking) and §8.6 (the Development panel).
 *
 * Linking a repository registers the hook on the provider through its API, with a secret this
 * application generated, so nobody pastes a URL into GitHub by hand and gets it wrong. From an
 * item: create a branch, open a pull request, link an existing one. From a release: a GitHub
 * release with the notes as its body, on the repository the release's items touched most.
 *
 * Every call goes through the provider's client and therefore through the connection's circuit
 * breaker; a paused connection refuses with the reason.
 */
class CodeHosts
{
    public function __construct(
        private readonly IntegrationEvents $events,
        private readonly Releases $releases,
    ) {}

    /** The repositories the connection can see, for the picker — §8.1. */
    public function available(IntegrationConnection $connection): array
    {
        return $this->provider($connection)->repositories($connection);
    }

    /**
     * Link one repository to one project, and register the hook — §8.1.
     *
     * @param  array{id: string, full_name: string, default_branch: string, url: string}  $repo
     */
    public function link(IntegrationConnection $connection, int $projectId, array $repo): WorkRepository
    {
        $repository = WorkRepository::query()->updateOrCreate(
            ['integration_connection_id' => $connection->getKey(), 'external_id' => (string) $repo['id']],
            [
                'project_id' => $projectId,
                'full_name' => $repo['full_name'],
                'default_branch' => $repo['default_branch'] ?: 'main',
                'url' => $repo['url'] ?? null,
                'linked_at' => now(),
                'unlinked_at' => null,
            ],
        );

        if (blank($repository->webhook_id)) {
            try {
                $hookId = $this->provider($connection)->registerWebhook($repository, $this->hookUrl($connection), $connection->webhook_secret);
                $repository->forceFill(['webhook_id' => $hookId])->save();
            } catch (RuntimeException $e) {
                // Linked without a hook: the page says so and offers to try again. A link that
                // failed outright because GitHub was down for a minute is worse.
                $repository->forceFill(['webhook_id' => null])->save();

                throw new RuntimeException('Linked, but the webhook could not be registered: '.$e->getMessage(), previous: $e);
            }
        }

        return $repository;
    }

    /**
     * Unlink: the hook comes off the provider and the row is marked, never deleted.
     *
     * The commits, branches and pull requests it recorded are part of how the items got done, and
     * the fact tables cascade on the repository row — so deleting it would have erased the history
     * the confirmation promised to keep. An unlinked repository delivers nothing (the handler skips
     * it) and is offered nowhere; linking it again clears the mark.
     */
    public function unlink(WorkRepository $repository): void
    {
        try {
            $this->provider($repository->connection)->removeWebhook($repository);
        } catch (RuntimeException) {
            // The hook may already be gone, or the token may have lost the right. The link goes
            // either way; a stray hook delivers to a repository that no longer accepts.
        }

        $repository->forceFill(['webhook_id' => null, 'unlinked_at' => now()])->save();
    }

    /** Where the provider should deliver for this connection. */
    public function hookUrl(IntegrationConnection $connection): string
    {
        $company = \App\Modules\Core\Models\Company::current();

        return route('work.hooks.inbound', [
            'provider' => $connection->provider,
            'company' => $company?->slug ?? 'company',
            'connection' => $connection->getKey(),
        ]);
    }

    /** The repositories a project may act on — what the Development panel offers. */
    public function repositoriesFor(WorkItem $item): Collection
    {
        return WorkRepository::query()
            ->where('project_id', $item->project_id)
            ->linked()
            ->with('connection')
            ->orderBy('full_name')
            ->get();
    }

    /** `WRK-12-export-fails-on-safari` — §8.6's branch name, cut to something a shell is happy with. */
    public function branchNameFor(WorkItem $item): string
    {
        return $item->key.'-'.Str::limit(Str::slug($item->title), 40, '');
    }

    /** Create a branch for an item from the repository's default branch — §8.6. */
    public function createBranch(WorkItem $item, WorkRepository $repository, ?User $by = null): WorkItemBranch
    {
        $name = $this->branchNameFor($item);
        $made = $this->provider($repository->connection)->createBranch($repository, $name);

        $branch = WorkItemBranch::query()->firstOrCreate(
            ['work_repository_id' => $repository->getKey(), 'name' => $made['name'], 'work_item_id' => $item->getKey()],
            ['url' => $made['url']],
        );

        // The same automation the inbound branch gets — §8.4 — because the team should not have to
        // learn that a branch made from here behaves differently from one made in a terminal.
        if ((bool) setting('work.branch_starts_progress', true) && $item->status?->category === WorkStatus::TODO) {
            $start = $item->workflow()?->firstOf(WorkStatus::IN_PROGRESS);

            if ($start !== null) {
                try {
                    $item->moveTo($start, $by);
                } catch (RuntimeException) {
                    // A rule refused it; the branch exists regardless.
                }
            }
        }

        return $branch;
    }

    /** Open a pull request from a linked branch, title and body prefilled — §8.6. */
    public function openPullRequest(WorkItem $item, WorkItemBranch $branch, ?User $by = null, ?string $title = null, ?string $body = null): WorkItemPullRequest
    {
        $repository = $branch->repository;

        $opened = $this->provider($repository->connection)->openPullRequest(
            $repository,
            $branch->name,
            $repository->default_branch,
            $title ?? $item->display_label,
            $body ?? (string) $item->description,
        );

        return WorkItemPullRequest::query()->updateOrCreate(
            ['work_repository_id' => $repository->getKey(), 'number' => $opened['number'], 'work_item_id' => $item->getKey()],
            ['title' => $opened['title'], 'url' => $opened['url'], 'head_branch' => $branch->name, 'state' => WorkItemPullRequest::OPEN],
        );
    }

    /** Paste a pull request URL — §8.6's *Link existing*. */
    public function linkPullRequest(WorkItem $item, string $url): WorkItemPullRequest
    {
        foreach ($this->repositoriesFor($item) as $repository) {
            $parsed = $this->provider($repository->connection)->parsePullRequestUrl($url);

            if ($parsed === null || strcasecmp($parsed[0], $repository->full_name) !== 0) {
                continue;
            }

            return WorkItemPullRequest::query()->firstOrCreate(
                ['work_repository_id' => $repository->getKey(), 'number' => $parsed[1], 'work_item_id' => $item->getKey()],
                ['title' => 'Pull request #'.$parsed[1], 'url' => $url, 'state' => WorkItemPullRequest::OPEN],
            );
        }

        throw new RuntimeException('That URL is not a pull request on a repository linked to this project.');
    }

    /**
     * A GitHub release with the notes as its body — §8.6 — on the repository the release's items
     * touched most. Nothing to pick when no item of the release has a commit or a pull request.
     */
    public function createRelease(WorkRelease $release): array
    {
        $repository = $this->repositoryTouchedMost($release);

        if ($repository === null) {
            throw new RuntimeException('None of this release\'s items has a commit or a pull request on a linked repository, so there is nowhere to publish it.');
        }

        $tag = Str::of($release->name)->trim()->replaceMatches('/\s+/', '-')->toString();

        return $this->provider($repository->connection)->createRelease($repository, $tag, $release->name, $this->releases->notes($release));
    }

    private function repositoryTouchedMost(WorkRelease $release): ?WorkRepository
    {
        $itemIds = $release->items()->pluck('id');

        if ($itemIds->isEmpty()) {
            return null;
        }

        $counts = collect()
            ->merge(\App\Modules\Work\Models\WorkItemCommit::query()->whereIn('work_item_id', $itemIds)->pluck('work_repository_id'))
            ->merge(WorkItemPullRequest::query()->whereIn('work_item_id', $itemIds)->pluck('work_repository_id'))
            ->countBy()
            ->sortDesc();

        $id = $counts->keys()->first();

        return $id === null ? null : WorkRepository::query()->with('connection')->find($id);
    }

    public function provider(IntegrationConnection $connection): CodeProvider
    {
        return $this->events->providerFor($connection);
    }

    /** Whether any code host is registered — what decides if the panel exists at all. */
    public static function anyRegistered(): bool
    {
        return Integrations::ofKind(Integrations::CODE) !== [];
    }
}
