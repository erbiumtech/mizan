<?php

namespace App\Modules\Work\Services;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Support\Rank;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Making a work item, which is mostly about the number on it.
 *
 * **The key is the one thing here that cannot be got wrong twice.** `WRK-12` ends up in a commit
 * message, a branch name and somebody's chat within minutes of being created; two items sharing one
 * is a reference that resolves to the wrong thing forever after. So the sequence is taken under a
 * row lock on the project, and a unique index on `(project_id, number)` stands behind the lock —
 * if the lock is ever bypassed the insert fails loudly rather than quietly duplicating.
 *
 * `next_item_number` rather than `max(number) + 1`: a deleted item must not hand its number to the
 * next one.
 */
class WorkItems
{
    /** Words in a project code that say "this is a project" rather than which one. */
    private const CODE_NOISE = ['PRJ', 'PROJ', 'PROJECT', 'JOB'];

    /**
     * Give a project its key, once.
     *
     * Derived from the code — `PRJ-ERP-01` becomes `ERP` — because a company that has been running
     * projects for a year should not have to name them all again. Refused once an item exists: every
     * commit message that ever referenced one would be pointing at nothing.
     */
    public function key(Project $project, ?string $wanted = null): string
    {
        if (filled($project->key) && $wanted === null) {
            return $project->key;
        }

        if (filled($project->key) && WorkItem::query()->where('project_id', $project->getKey())->exists()) {
            throw new RuntimeException('This project already has work items, so its key cannot change — '
                .$project->key.' is in commit messages and branch names by now.');
        }

        $words = Str::of($wanted ?? $project->code ?? $project->name)
            ->ascii()
            ->replaceMatches('/[^A-Za-z]/', ' ')
            ->squish()
            ->explode(' ')
            ->filter()
            ->values();

        /*
         * **The noise word, and why it is worth a list.**
         *
         * Company project codes look like `PRJ-ERP-01` and `PRJ-CRM-02` — a constant prefix saying
         * "this is a project", then the part that identifies it. Taking initials of every word gives
         * PE and PC: useless to read and one letter from colliding. Dropping the prefix gives ERP and
         * CRM, which is what somebody would have typed anyway.
         *
         * Only dropped when something is left: a project whose code is only `PRJ` keeps it.
         */
        $meaningful = $words->reject(
            fn (string $word): bool => in_array(Str::upper($word), self::CODE_NOISE, true),
        )->values();

        $words = $meaningful->isNotEmpty() ? $meaningful : $words;

        // One word: its first letters. Several: their initials — "Mobile App Rewrite" is MAR.
        $key = $words->count() > 1
            ? $words->map(fn (string $word): string => $word[0])->implode('')
            : (string) $words->first();

        $key = Str::upper(Str::substr($key, 0, 6));

        $key = $key !== '' ? $key : 'PRJ';

        // Unique across the company, because the key is what a commit message carries and nothing
        // in a commit message says which project it meant.
        $base = $key;
        $suffix = 1;

        while (Project::query()->where('key', $key)->whereKeyNot($project->getKey())->exists()) {
            $key = Str::substr($base, 0, 5).$suffix++;
        }

        $project->forceFill(['key' => $key])->save();

        return $key;
    }

    /**
     * Create an item, numbered and ranked.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(Project $project, array $attributes, ?User $by = null): WorkItem
    {
        $key = $this->key($project);
        $status = $this->openingStatus($project, $attributes['work_status_id'] ?? null);

        return DB::connection($project->getConnectionName())->transaction(function () use ($project, $attributes, $by, $key, $status): WorkItem {
            // The lock is the whole point: two people pressing Create at the same moment must not
            // both read 12.
            $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->first();
            $number = (int) ($locked->next_item_number ?: 1);
            $locked->forceFill(['next_item_number' => $number + 1])->save();

            return WorkItem::create([
                ...$attributes,
                'project_id' => $project->getKey(),
                'number' => $number,
                'key' => $key.'-'.$number,
                'work_status_id' => $status->getKey(),
                'reporter_user_id' => $attributes['reporter_user_id'] ?? $by?->getKey(),
                'rank' => $attributes['rank'] ?? Rank::last($this->lowestRank($project)),
            ]);
        });
    }

    /** Where a new item starts: the first `todo` of the project's workflow. */
    public function openingStatus(Project $project, int|string|null $given = null): WorkStatus
    {
        if ($given !== null) {
            $status = WorkStatus::query()->whereKey($given)->first();

            if ($status !== null) {
                return $status;
            }
        }

        $workflow = $project->work_workflow_id !== null
            ? WorkWorkflow::query()->whereKey($project->work_workflow_id)->first()
            : WorkWorkflow::default();

        $status = $workflow?->firstOf(WorkStatus::TODO) ?? $workflow?->statuses()->first();

        if ($status === null) {
            throw new RuntimeException('This company has no workflow to put a work item in. '
                .'Seed one under Settings → Workflows before creating items.');
        }

        return $status;
    }

    private function lowestRank(Project $project): ?string
    {
        return WorkItem::query()
            ->where('project_id', $project->getKey())
            ->orderByDesc('rank')
            ->value('rank');
    }
}
