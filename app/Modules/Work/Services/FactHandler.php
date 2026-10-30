<?php

namespace App\Modules\Work\Services;

use App\Modules\Core\Models\User;
use App\Modules\Work\Integrations\Facts\BranchFact;
use App\Modules\Work\Integrations\Facts\CommitFact;
use App\Modules\Work\Integrations\Facts\Fact;
use App\Modules\Work\Integrations\Facts\PullRequestFact;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\IntegrationIdentity;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkItemBranch;
use App\Modules\Work\Models\WorkItemCommit;
use App\Modules\Work\Models\WorkItemPullRequest;
use App\Modules\Work\Models\WorkRepository;
use App\Modules\Work\Models\WorkStatus;
use App\Support\Integrations;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What the facts do — docs/project-management-plan.md §8.4.
 *
 * Provider-free: a commit, a branch and a pull request arrive as `Fact`s and nothing here knows
 * which host sent them. Every automatic move goes through `moveTo()`, obeys the transition
 * rules, and is written to the activity log as *by GitHub (via Ahmed Raza)* — the integration
 * never acts anonymously, and a refused transition is recorded rather than forced.
 *
 * **An unmatched identity links and does nothing else.** A commit saying `WRK-12 #done` from a
 * username nobody here has claimed links the commit to WRK-12 and leaves it where it is; the
 * connection's page says how many are waiting on a match.
 */
class FactHandler
{
    /** `WRK-12`: three to six capitals, a dash, digits. Word-bounded so `AWRK-12x` is not one. */
    private const KEY_PATTERN = '/\b([A-Z][A-Z0-9]{1,5}-\d+)\b/';

    public function __construct(
        private readonly Identities $identities,
        private readonly WorkTime $time,
    ) {}

    /** @param  array<int, Fact>  $facts */
    public function handle(IntegrationConnection $connection, array $facts): void
    {
        foreach ($facts as $fact) {
            $repository = $connection->repositories()->linked()->where('external_id', $fact->repositoryExternalId)->first();

            // A delivery for a repository nobody linked, or one since unlinked: the hook outlived
            // the link. Nothing to do.
            if ($repository === null) {
                continue;
            }

            $identity = $this->identities->resolve($connection, $fact->author);

            match (true) {
                $fact instanceof CommitFact => $this->commit($repository, $fact, $identity),
                $fact instanceof BranchFact => $this->branch($repository, $fact, $identity),
                $fact instanceof PullRequestFact => $this->pullRequest($repository, $fact, $identity),
                default => null,
            };
        }
    }

    /** The keys a piece of text names, as the items of this project — in order, once each. */
    public function itemsNamedIn(WorkRepository $repository, string ...$texts): Collection
    {
        $keys = collect();

        foreach ($texts as $text) {
            if (preg_match_all(self::KEY_PATTERN, strtoupper($text), $matches)) {
                $keys = $keys->merge($matches[1]);
            }
        }

        $keys = $keys->unique()->values();

        if ($keys->isEmpty()) {
            return collect();
        }

        return WorkItem::query()
            ->where('project_id', $repository->project_id)
            ->whereIn('key', $keys->all())
            ->get();
    }

    // ---------------------------------------------------------------- commits

    private function commit(WorkRepository $repository, CommitFact $fact, ?IntegrationIdentity $identity): void
    {
        foreach ($this->itemsNamedIn($repository, $fact->message) as $item) {
            WorkItemCommit::query()->firstOrCreate(
                ['work_repository_id' => $repository->getKey(), 'sha' => $fact->sha, 'work_item_id' => $item->getKey()],
                [
                    'message' => Str::limit($fact->firstLine(), 250),
                    'integration_identity_id' => $identity?->getKey(),
                    'committed_at' => $fact->committedAt,
                    'url' => $fact->url,
                ],
            );

            $this->smartCommit($repository, $item, $fact, $identity);
        }
    }

    /**
     * Smart commits — `WRK-12 #done`, `#in-review`, `#time 2h`, `#comment fixed it` — §8.4.
     *
     * Only in the name of a matched user. The commands are read from the whole message, so a
     * body line `#time 1h 30m` counts; a `#` that opens a Markdown heading does not, because a
     * heading is not one of the four words.
     */
    private function smartCommit(WorkRepository $repository, WorkItem $item, CommitFact $fact, ?IntegrationIdentity $identity): void
    {
        $user = $identity?->user();

        if (! $user instanceof User) {
            return;
        }

        $message = $fact->message;
        $acted = [];

        if (preg_match('/#(done|resolve|close)\b/i', $message)) {
            $acted[] = $this->moveToCategory($item, WorkStatus::DONE, $user, $repository, 'commit '.substr($fact->sha, 0, 7), 'Done');
        }

        if (preg_match('/#(in-review|review)\b/i', $message)) {
            $acted[] = $this->moveToReview($item, $user, $repository, 'commit '.substr($fact->sha, 0, 7));
        }

        if (preg_match('/#time\s+((?:\d+(?:\.\d+)?[hm]\s*)+)/i', $message, $m)) {
            $minutes = $this->minutesIn($m[1]);

            if ($minutes > 0 && $this->time->isAvailable()) {
                try {
                    $entry = $this->time->log($item, $user, $minutes, ($fact->committedAt ?? now())->toDateString(), $fact->firstLine());
                    $entry->forceFill(['source' => WorkTime::SMART_COMMIT, 'external_ref' => $repository->getKey().':'.$fact->sha])->saveQuietly();
                    $acted[] = 'logged '.WorkTime::duration($minutes);
                } catch (RuntimeException $e) {
                    $acted[] = 'could not log time: '.$e->getMessage();
                }
            }
        }

        if (preg_match('/#comment\s+(.+)$/im', $message, $m)) {
            $item->comments()->create(['user_id' => $user->getKey(), 'body' => trim($m[1])]);
            $acted[] = 'commented';
        }

        if ($acted !== []) {
            $this->record($item, $repository, $user, 'Smart commit '.substr($fact->sha, 0, 7).': '.implode('; ', array_filter($acted)));
        }
    }

    /** `2h`, `1h 30m`, `45m` → minutes. */
    private function minutesIn(string $spec): int
    {
        $minutes = 0;

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*([hm])/i', $spec, $parts, PREG_SET_ORDER)) {
            foreach ($parts as [$all, $n, $unit]) {
                $minutes += strtolower($unit) === 'h' ? (int) round((float) $n * 60) : (int) $n;
            }
        }

        return $minutes;
    }

    // ---------------------------------------------------------------- branches

    private function branch(WorkRepository $repository, BranchFact $fact, ?IntegrationIdentity $identity): void
    {
        foreach ($this->itemsNamedIn($repository, $fact->name) as $item) {
            $branch = WorkItemBranch::query()->firstOrCreate(
                ['work_repository_id' => $repository->getKey(), 'name' => $fact->name, 'work_item_id' => $item->getKey()],
                ['url' => $fact->url],
            );

            if ($fact->deleted) {
                $branch->forceFill(['deleted_at' => now()])->save();

                continue;
            }

            if ($branch->isDeleted()) {
                $branch->forceFill(['deleted_at' => null])->save();
            }

            // The one automation every team asked for: a branch for an item in todo starts it —
            // §8.4. A setting, on by default.
            if ((bool) setting('work.branch_starts_progress', true) && $item->status?->category === WorkStatus::TODO) {
                $this->moveToCategory($item, WorkStatus::IN_PROGRESS, $identity?->user(), $repository, 'branch '.$fact->name, 'In progress');
            }
        }
    }

    // ---------------------------------------------------------------- pull requests

    private function pullRequest(WorkRepository $repository, PullRequestFact $fact, ?IntegrationIdentity $identity): void
    {
        foreach ($this->itemsNamedIn($repository, $fact->title, $fact->headBranch) as $item) {
            $pr = WorkItemPullRequest::query()->firstOrNew(
                ['work_repository_id' => $repository->getKey(), 'number' => $fact->number, 'work_item_id' => $item->getKey()],
            );

            $pr->fill([
                'title' => Str::limit($fact->title, 250),
                'integration_identity_id' => $identity?->getKey() ?? $pr->integration_identity_id,
                'url' => $fact->url ?? $pr->url,
                'head_branch' => $fact->headBranch ?: $pr->head_branch,
                'merged_at' => $fact->mergedAt ?? $pr->merged_at,
                'checks_state' => $fact->checksState ?? $pr->checks_state,
                'state' => match ($fact->action) {
                    PullRequestFact::MERGED => WorkItemPullRequest::MERGED,
                    PullRequestFact::CLOSED => WorkItemPullRequest::CLOSED,
                    PullRequestFact::OPENED => WorkItemPullRequest::OPEN,
                    default => $pr->exists ? $pr->state : WorkItemPullRequest::OPEN,
                },
            ])->save();

            $category = $item->status?->category;
            $user = $identity?->user();

            if ($fact->action === PullRequestFact::OPENED && in_array($category, [WorkStatus::TODO, WorkStatus::IN_PROGRESS], true)) {
                $this->moveToReview($item, $user, $repository, 'pull request #'.$fact->number);
            }

            if ($fact->action === PullRequestFact::MERGED && $this->mergeMovesToDone($repository)) {
                $this->moveToCategory($item, WorkStatus::DONE, $user, $repository, 'pull request #'.$fact->number.' merged', 'Done');
            }

            if ($fact->action === PullRequestFact::CLOSED) {
                $this->record($item, $repository, $user, 'Pull request #'.$fact->number.' was closed without merging.');
            }
        }
    }

    /** The project's own say, else the company default — §8.4. */
    private function mergeMovesToDone(WorkRepository $repository): bool
    {
        $project = $repository->project;

        if ($project !== null && $project->work_merge_moves_to_done !== null) {
            return (bool) $project->work_merge_moves_to_done;
        }

        return (bool) setting('work.merge_moves_to_done', true);
    }

    // ---------------------------------------------------------------- moving, on the record

    /**
     * To *In review*: the workflow's chosen review status, else one so named, else nowhere.
     *
     * Nowhere rather than a guess, because three categories are kept on purpose (§16.4) and a
     * review is in progress by any honest reading; a team that wants the move names the status.
     */
    private function moveToReview(WorkItem $item, ?User $user, WorkRepository $repository, string $because): string
    {
        $workflow = $item->workflow();

        $review = $workflow?->review_status_id !== null
            ? WorkStatus::query()->find($workflow->review_status_id)
            : $workflow?->statuses()->whereRaw('LOWER(name) = ?', ['in review'])->first();

        if ($review === null) {
            return 'no review status to move to';
        }

        return $this->move($item, $review, $user, $repository, $because);
    }

    private function moveToCategory(WorkItem $item, string $category, ?User $user, WorkRepository $repository, string $because, string $resolution): string
    {
        $target = $item->workflow()?->firstOf($category);

        if ($target === null) {
            return 'no '.$category.' status to move to';
        }

        return $this->move($item, $target, $user, $repository, $because, $category === WorkStatus::DONE ? $resolution : null);
    }

    /** One transition, through `moveTo()`, recorded either way. */
    private function move(WorkItem $item, WorkStatus $target, ?User $user, WorkRepository $repository, string $because, ?string $resolution = null): string
    {
        if ((int) $item->work_status_id === (int) $target->getKey()) {
            return 'already '.$target->name;
        }

        try {
            $item->moveTo($target, $user, $resolution);
            $this->record($item, $repository, $user, 'Moved to '.$target->name.' by '.$because.'.');

            return 'moved to '.$target->name;
        } catch (RuntimeException $e) {
            // Refused by a workflow rule. Recorded, never forced: the rule is the team's.
            $this->record($item, $repository, $user, 'Could not move to '.$target->name.' by '.$because.': '.$e->getMessage());

            return 'refused: '.$e->getMessage();
        }
    }

    /** *by GitHub (via Ahmed Raza)* — the integration never acts anonymously. */
    private function record(WorkItem $item, WorkRepository $repository, ?User $user, string $description): void
    {
        $provider = Integrations::label($repository->connection?->provider ?? 'integration');

        activity('Integration')
            ->performedOn($item)
            ->causedBy($user)
            ->withProperties(['provider' => $provider, 'repository' => $repository->full_name, 'via' => $user?->name])
            ->log('By '.$provider.($user !== null ? ' (via '.$user->name.')' : '').': '.$description);
    }
}
