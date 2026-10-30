<?php

namespace App\Modules\Work\Support;

use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Services\Sprints;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The four work reports in the catalogue — docs/project-management-plan.md §7.
 *
 * **All four are read from `work_items` and its three stamps, and nothing else.** `created_at`,
 * `started_at` and `resolved_at` are written by one method (`WorkItem::moveTo()`) and never by hand,
 * which is what makes a cycle time computed from them worth reading — and what lets cumulative flow
 * be drawn retrospectively without a nightly snapshot table: where an item was on a given day is
 * derivable from when it started and when it finished.
 *
 * The plan put burndown here too. It is on the sprint's own page instead, with velocity beside it,
 * because the catalogue's shape is a table and a burndown is a line (`ViewWorkSprint` records the
 * decision). Velocity *is* here as well, as the table it also is: the sprint page answers "how is
 * this sprint going", the hub answers "how do the projects compare".
 */
class WorkReports
{
    use ReportShapes;

    /** How many weeks back the two weekly reports look. Stated in each subtitle. */
    private const WEEKS = 12;

    /**
     * Velocity — what each closed sprint delivered, per project.
     *
     * The figures are `Sprints::velocity()`'s, unchanged, so the table here and the one on the sprint
     * page can never disagree about a sprint.
     */
    public function velocity(string $asOf): array
    {
        $on = Carbon::parse($asOf);
        $rows = [];
        $sprints = 0;
        $points = 0.0;
        $items = 0;

        foreach ($this->projectsWithWork() as $project) {
            foreach (app(Sprints::class)->velocity($project) as $sprint) {
                // As at the date asked for: a sprint closed after it has not happened yet from there.
                if ($sprint['closed_at'] !== null && $sprint['closed_at']->gt($on->copy()->endOfDay())) {
                    continue;
                }

                $rows[] = [
                    $project->name,
                    $sprint['sprint'],
                    $sprint['started_at']?->format('d M Y') ?? '—',
                    $sprint['closed_at']?->format('d M Y') ?? '—',
                    $this->count($sprint['items']),
                    $this->figure($sprint['points']),
                    $this->count($sprint['carried']),
                ];

                $sprints++;
                $points += $sprint['points'];
                $items += $sprint['items'];
            }
        }

        return $this->table(
            'WorkVelocity',
            'Velocity',
            $this->subtitle('the last eight closed sprints of every project, to '.$on->toDateString()),
            ['Project', 'Sprint', 'Started', 'Closed', 'Items', 'Points', 'Carried in'],
            'minmax(0, 12rem) minmax(0, 10rem) 8rem 8rem 6rem 6rem 7rem',
            [4, 5, 6],
            $rows,
            [
                ['label' => 'SPRINTS', 'value' => (float) $sprints, 'accent' => false],
                ['label' => 'POINTS PER SPRINT', 'value' => $sprints > 0 ? round($points / $sprints, 1) : 0.0, 'accent' => true],
            ],
            $sprints > 0
                ? mb_strtoupper($items.' items delivered across '.$sprints.' sprints; counted between when each sprint actually started and closed')
                : 'NO SPRINT HAS BEEN CLOSED YET',
            $rows === [] ? null : ['Total — '.$sprints.' sprints', '', '', '', number_format($items), $this->figure($points), ''],
            'No sprint has been closed on any project.',
        );
    }

    /**
     * Cycle and lead time — per project, by type, over items resolved in the period.
     *
     * Cycle time is `started_at` to `resolved_at`: how long it took once somebody picked it up. Lead
     * time is `created_at` to `resolved_at`: how long the asker waited. The gap between them is the
     * queue, and it is the figure a team can do least about by working harder.
     *
     * Medians rather than means, because one item that sat for a quarter would otherwise make a
     * week-long cycle read as a month.
     */
    public function cycleTime(string $asOf): array
    {
        $period = ReportPeriod::toDate($asOf);

        $resolved = WorkItem::query()
            ->with('project')
            ->whereNull('archived_at')
            ->whereNotNull('resolved_at')
            ->whereBetween('resolved_at', [
                Carbon::parse($period['from'])->startOfDay(),
                Carbon::parse($period['to'])->endOfDay(),
            ])
            ->get();

        $rows = [];
        $allCycle = [];
        $allLead = [];

        $groups = $resolved
            ->groupBy(fn (WorkItem $item): string => ($item->project?->name ?? '').'|'.ucfirst(strtolower((string) $item->type)))
            ->sortKeys();

        foreach ($groups as $key => $items) {
            [$project, $type] = explode('|', $key, 2);

            $cycle = $items
                ->filter(fn (WorkItem $i): bool => $i->started_at !== null)
                ->map(fn (WorkItem $i): float => $this->days($i->started_at, $i->resolved_at));
            $lead = $items->map(fn (WorkItem $i): float => $this->days($i->created_at, $i->resolved_at));

            $rows[] = [
                $project,
                $type,
                $this->count($items->count()),
                $this->median($cycle),
                $this->median($lead),
                // Resolved without ever being in progress: closed as won't-do, or moved straight to
                // done. Shown because a high number here is a cycle time that means little.
                $this->count($items->count() - $cycle->count()),
            ];

            $allCycle = [...$allCycle, ...$cycle->all()];
            $allLead = [...$allLead, ...$lead->all()];
        }

        return $this->table(
            'WorkCycleTime',
            'Cycle & Lead Time',
            $this->subtitle('items resolved between '.$period['from'].' and '.$period['to']),
            ['Project', 'Type', 'Resolved', 'Cycle (days)', 'Lead (days)', 'Never started'],
            'minmax(0, 12rem) 8rem 7rem 8rem 8rem 8rem',
            [2, 3, 4, 5],
            $rows,
            [
                ['label' => 'RESOLVED', 'value' => (float) $resolved->count(), 'accent' => false],
                ['label' => 'MEDIAN CYCLE DAYS', 'value' => (float) ($this->median(collect($allCycle)) ?: 0), 'accent' => true],
            ],
            $resolved->isEmpty()
                ? 'NOTHING WAS RESOLVED IN THIS PERIOD'
                : mb_strtoupper('medians. cycle is started to resolved; lead is created to resolved; the difference is the queue'),
            $rows === [] ? null : [
                'All — '.$resolved->count().' items', '', '',
                $this->median(collect($allCycle)),
                $this->median(collect($allLead)),
                number_format($resolved->count() - count($allCycle)),
            ],
            'Nothing was resolved in this period.',
        );
    }

    /**
     * Created against resolved — per project, per week, over the last twelve weeks.
     *
     * The net column is the one to read: a project resolving fewer than it files, week after week,
     * is a project whose backlog is a list of things that will never happen, however good the
     * velocity looks.
     */
    public function createdVsResolved(string $asOf): array
    {
        $on = Carbon::parse($asOf)->endOfDay();
        $weeks = $this->weeks($on);
        $from = $weeks[0]['from'];

        $items = WorkItem::query()
            ->with('project')
            ->whereNull('archived_at')
            ->where(fn (Builder $q) => $q->where('created_at', '>=', $from)->orWhere('resolved_at', '>=', $from))
            ->get();

        $rows = [];
        $created = 0;
        $resolved = 0;

        foreach ($this->projectsWithWork() as $project) {
            $mine = $items->where('project_id', $project->getKey());

            foreach ($weeks as $week) {
                $c = $mine->filter(fn (WorkItem $i): bool => $i->created_at->between($week['from'], $week['to']))->count();
                $r = $mine->filter(fn (WorkItem $i): bool => $i->resolved_at !== null && $i->resolved_at->between($week['from'], $week['to']))->count();

                if ($c === 0 && $r === 0) {
                    continue;
                }

                $rows[] = [
                    $project->name,
                    $week['label'],
                    $this->count($c),
                    $this->count($r),
                    $this->signed($r - $c),
                ];

                $created += $c;
                $resolved += $r;
            }
        }

        return $this->table(
            'WorkCreatedVsResolved',
            'Created vs Resolved',
            $this->subtitle('the '.self::WEEKS.' weeks to '.$on->toDateString().', per project'),
            ['Project', 'Week', 'Created', 'Resolved', 'Net'],
            'minmax(0, 12rem) 10rem 7rem 7rem 7rem',
            [2, 3, 4],
            $rows,
            [
                ['label' => 'CREATED', 'value' => (float) $created, 'accent' => false],
                ['label' => 'RESOLVED', 'value' => (float) $resolved, 'accent' => true],
            ],
            $rows === []
                ? 'NOTHING WAS CREATED OR RESOLVED IN THESE WEEKS'
                : mb_strtoupper('net is resolved minus created: below zero, the backlog grew that week'),
            $rows === [] ? null : ['Total', '', number_format($created), number_format($resolved), $this->signed($resolved - $created)],
            'Nothing was created or resolved in these weeks.',
        );
    }

    /**
     * Cumulative flow — how many items were in each category at the end of each week, per project.
     *
     * **Drawn from the three stamps rather than a snapshot table**, which is why it can be read for
     * weeks before anybody thought to look: at the end of a week an item was *done* if it had
     * resolved by then, *in progress* if it had started and not resolved, and *to do* if it existed
     * and had done neither. An item reopened since reads as having been open all along, which is
     * the one way this differs from a snapshot and is stated in the help.
     *
     * Per project rather than per board (the plan's unit): a board is a filter over a project's
     * items, and a cumulative flow over a filter is a chart of the filter as much as of the work.
     */
    public function cumulativeFlow(string $asOf): array
    {
        $on = Carbon::parse($asOf)->endOfDay();
        $weeks = $this->weeks($on);

        $items = WorkItem::query()
            ->whereNull('archived_at')
            ->where('created_at', '<=', $on)
            ->get();

        $rows = [];
        $latest = ['todo' => 0, 'in_progress' => 0, 'done' => 0];

        $lastWeek = array_key_last($weeks);

        foreach ($this->projectsWithWork() as $project) {
            $mine = $items->where('project_id', $project->getKey());

            foreach ($weeks as $index => $week) {
                $at = $week['to'];
                $existed = $mine->filter(fn (WorkItem $i): bool => $i->created_at->lte($at));

                if ($existed->isEmpty()) {
                    continue;
                }

                $done = $existed->filter(fn (WorkItem $i): bool => $i->resolved_at !== null && $i->resolved_at->lte($at))->count();
                $started = $existed->filter(fn (WorkItem $i): bool => $i->started_at !== null && $i->started_at->lte($at)
                    && ! ($i->resolved_at !== null && $i->resolved_at->lte($at)))->count();
                $todo = $existed->count() - $done - $started;

                $rows[] = [
                    $project->name,
                    $week['label'],
                    $this->count($todo),
                    $this->count($started),
                    $this->count($done),
                    $this->count($existed->count()),
                ];

                if ($index === $lastWeek) {
                    $latest['todo'] += $todo;
                    $latest['in_progress'] += $started;
                    $latest['done'] += $done;
                }
            }
        }

        return $this->table(
            'WorkCumulativeFlow',
            'Cumulative Flow',
            $this->subtitle('where every item stood at the end of each of the '.self::WEEKS.' weeks to '.$on->toDateString()),
            ['Project', 'Week', 'To do', 'In progress', 'Done', 'Total'],
            'minmax(0, 12rem) 10rem 7rem 8rem 7rem 7rem',
            [2, 3, 4, 5],
            $rows,
            [
                ['label' => 'IN PROGRESS NOW', 'value' => (float) $latest['in_progress'], 'accent' => true],
                ['label' => 'TO DO NOW', 'value' => (float) $latest['todo'], 'accent' => false],
            ],
            $rows === []
                ? 'NO WORK ITEMS EXISTED IN THESE WEEKS'
                : mb_strtoupper('a widening in-progress band is work started faster than it is finished'),
            null,
            'No work items existed in these weeks.',
        );
    }

    /** @return Collection<int, Project> */
    private function projectsWithWork(): Collection
    {
        return Project::query()
            ->whereIn('id', WorkItem::query()->select('project_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * The last twelve calendar weeks, Monday to Sunday, ending with the week holding the date.
     *
     * @return array<int, array{label: string, from: Carbon, to: Carbon}>
     */
    private function weeks(Carbon $on): array
    {
        $weeks = [];
        $end = $on->copy()->endOfWeek();

        for ($i = self::WEEKS - 1; $i >= 0; $i--) {
            $to = $end->copy()->subWeeks($i)->endOfDay();
            $from = $to->copy()->startOfWeek()->startOfDay();

            $weeks[] = ['label' => 'w/c '.$from->format('d M'), 'from' => $from, 'to' => $to->min($on)];
        }

        return $weeks;
    }

    private function days(Carbon $from, ?Carbon $to): float
    {
        return $to === null ? 0.0 : round(max(0, $from->diffInMinutes($to)) / 1440, 1);
    }

    /** @param  Collection<int, float>  $values */
    private function median(Collection $values): string
    {
        if ($values->isEmpty()) {
            return '—';
        }

        return number_format((float) $values->median(), 1);
    }

    private function count(int $n): string
    {
        return $n === 0 ? '—' : number_format($n);
    }

    private function figure(float $n): string
    {
        return $n == 0.0 ? '—' : number_format($n, 1);
    }

    private function signed(int $n): string
    {
        return match (true) {
            $n > 0 => '+'.number_format($n),
            $n < 0 => '−'.number_format(abs($n)),
            default => '0',
        };
    }
}
