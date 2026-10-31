<?php

namespace App\Modules\Work\Services;

use App\Modules\Projects\Models\Project;
use App\Modules\Work\Events\SprintCompleted;
use App\Modules\Work\Events\SprintStarted;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkSprint;
use App\Modules\Work\Models\WorkStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Starting a sprint, closing one, and what the closed ones say about the team.
 *
 * **Closing is the consequential half.** Starting a sprint changes one row; closing one decides
 * what happens to everything unfinished in it, and gets the number the next planning meeting
 * argues about. Both are here rather than on the model, because both are several writes that must
 * happen together or not at all.
 *
 * See docs/project-management-plan.md §6.2.
 */
class Sprints
{
    /**
     * Begin a sprint.
     *
     * One active sprint per project, refused rather than queued: a team with two sprints running is
     * a team whose burndown means nothing and whose board shows a mixture of two commitments.
     *
     * @throws RuntimeException
     */
    public function start(WorkSprint $sprint): WorkSprint
    {
        if ($sprint->isClosed()) {
            throw new RuntimeException('That sprint is closed. Closed sprints stay closed — the figures for it are already counted.');
        }

        if ($sprint->isActive()) {
            return $sprint;
        }

        if (blank($sprint->starts_on) || blank($sprint->ends_on)) {
            throw new RuntimeException('A sprint needs a start and an end before it can run. The burndown is drawn between them.');
        }

        $running = WorkSprint::query()
            ->where('project_id', $sprint->project_id)
            ->active()
            ->whereKeyNot($sprint->getKey())
            ->first();

        if ($running !== null) {
            throw new RuntimeException($running->name.' is still running. Close it before starting another.');
        }

        $sprint->forceFill([
            'state' => WorkSprint::ACTIVE,
            'started_at' => now(),
        ])->save();

        SprintStarted::dispatch($sprint);

        return $sprint;
    }

    /**
     * Close a sprint, and say where the unfinished went.
     *
     * **The carry-over is recorded on each item rather than inferred.** A sprint that delivered
     * forty points having carried fifteen in from the last one is a different sprint from one that
     * started them, and nothing in the schema would say so afterwards — `sprint_id` has already
     * moved on by the time anybody asks.
     *
     * @param  ?WorkSprint  $moveTo  where the unfinished go; null sends them back to the backlog
     *
     * @throws RuntimeException
     */
    public function complete(WorkSprint $sprint, ?WorkSprint $moveTo = null): WorkSprint
    {
        if (! $sprint->isActive()) {
            throw new RuntimeException('Only a running sprint can be completed.');
        }

        if ($moveTo !== null && $moveTo->isClosed()) {
            throw new RuntimeException('Unfinished work cannot be moved into a closed sprint.');
        }

        if ($moveTo !== null && (int) $moveTo->getKey() === (int) $sprint->getKey()) {
            throw new RuntimeException('Unfinished work cannot be moved into the sprint being closed.');
        }

        $unfinished = $this->unfinished($sprint)->get();

        foreach ($unfinished as $item) {
            $item->forceFill([
                'sprint_id' => $moveTo?->getKey(),
                'carried_over' => (int) $item->carried_over + 1,
            ])->save();
        }

        $sprint->forceFill([
            'state' => WorkSprint::CLOSED,
            'closed_at' => now(),
        ])->save();

        SprintCompleted::dispatch($sprint, $moveTo);

        return $sprint;
    }

    /** What is still open in a sprint — the items a close has to decide about. */
    public function unfinished(WorkSprint $sprint): Builder
    {
        return WorkItem::query()
            ->where('sprint_id', $sprint->getKey())
            ->whereNull('archived_at')
            ->whereHas('status', fn (Builder $s) => $s->where('category', '!=', WorkStatus::DONE));
    }

    /**
     * What each closed sprint delivered, newest last — §6.2.
     *
     * **Computed, never stored.** A stored velocity is a number that was right on the day it was
     * written and silently wrong after any correction to the items behind it. Counted between
     * `started_at` and `closed_at` rather than the planned dates, because a sprint started two days
     * late delivered what it delivered in the days it had.
     *
     * Both figures are reported, because a team using points and a team using hours both exist and
     * neither should read the other's column.
     *
     * @return Collection<int, array{id: int, sprint: string, started_at: ?Carbon, closed_at: ?Carbon, points: float, minutes: int, items: int, carried: int}>
     */
    public function velocity(Project $project, int $last = 8): Collection
    {
        return WorkSprint::query()
            ->where('project_id', $project->getKey())
            ->where('state', WorkSprint::CLOSED)
            ->orderByDesc('closed_at')
            ->limit($last)
            ->get()
            ->map(function (WorkSprint $sprint): array {
                $delivered = WorkItem::query()
                    ->where('sprint_id', $sprint->getKey())
                    ->whereNotNull('resolved_at')
                    ->when($sprint->started_at, fn (Builder $q, Carbon $from) => $q->where('resolved_at', '>=', $from))
                    ->when($sprint->closed_at, fn (Builder $q, Carbon $to) => $q->where('resolved_at', '<=', $to))
                    ->get();

                return [
                    'id' => (int) $sprint->getKey(),
                    'sprint' => $sprint->name,
                    // When it actually ran — what the figures were counted between. The Velocity
                    // report in the hub prints them beside the figures.
                    'started_at' => $sprint->started_at,
                    'closed_at' => $sprint->closed_at,
                    'points' => (float) $delivered->sum(fn (WorkItem $i): float => (float) ($i->estimate_points ?? 0)),
                    'minutes' => (int) $delivered->sum(fn (WorkItem $i): int => (int) ($i->estimate_minutes ?? 0)),
                    'items' => $delivered->count(),
                    // How much of it was somebody else's sprint's work, finished here.
                    'carried' => $delivered->sum(fn (WorkItem $i): int => $i->carried_over > 0 ? 1 : 0),
                ];
            })
            ->reverse()
            ->values();
    }

    /**
     * The burndown of one sprint: what was left to do at the end of each day.
     *
     * Drawn from `resolved_at` rather than from a daily snapshot table, which means it is correct
     * retrospectively — a sprint whose items were re-estimated after the fact redraws honestly
     * instead of keeping whatever a nightly job happened to record.
     *
     * The ideal line is the total falling evenly across the days, which is what it is for: not a
     * target, but the thing the real line is read against.
     *
     * @return array<int, array{day: string, remaining: float, ideal: float}>
     */
    public function burndown(WorkSprint $sprint): array
    {
        $items = WorkItem::query()->where('sprint_id', $sprint->getKey())->get();

        if ($items->isEmpty() || blank($sprint->starts_on) || blank($sprint->ends_on)) {
            return [];
        }

        $weight = fn (WorkItem $item): float => (float) ($item->estimate_points ?? 1);
        $total = $items->sum($weight);

        $start = $sprint->starts_on->startOfDay();
        $end = $sprint->ends_on->endOfDay();
        $days = max(1, (int) $start->diffInDays($end));

        $points = [];

        for ($day = 0; $day <= $days; $day++) {
            $at = $start->copy()->addDays($day)->endOfDay();

            $done = $items
                ->filter(fn (WorkItem $i): bool => $i->resolved_at !== null && $i->resolved_at->lte($at))
                ->sum($weight);

            $points[] = [
                'day' => $at->format('d M'),
                'remaining' => round($total - $done, 1),
                'ideal' => round($total - ($total * $day / $days), 1),
            ];
        }

        return $points;
    }
}
