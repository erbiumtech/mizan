<?php

namespace App\Modules\Work\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkRelease;
use App\Modules\Work\Models\WorkSprint;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Epics and releases as bars on a month-scale timeline — docs/project-management-plan.md §6.5.
 *
 * **Read-only, and the plan says why**: dragging a bar to change a date is a nicety on top of a
 * date field. No dependencies between bars, no critical path (§17.3). An Epic's bar runs from the
 * earliest its children started (or it was created) to its due date, or to its children's latest
 * due date; its dated children sit as ticks beneath it. A release is a marker on the day it is
 * planned for, or went out. Sprints are drawn as a faint band so the bars have a calendar to be
 * read against.
 */
class Timeline extends Page
{
    use BelongsToModule;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 28;

    protected string $view = 'filament.work.timeline';

    #[Url]
    public ?int $project = null;

    public function getTitle(): string|Htmlable
    {
        return __('Timeline');
    }

    /**
     * The module and the permission. `BelongsToModule::canAccess()` checks the module alone, and a
     * panel user whose role lacks `WorkItemView` must not read a board through this page when the
     * item list and the release list refuse them.
     */
    public static function canAccess(): bool
    {
        return static::moduleIsAvailable() && (auth()->user()?->can('viewAny', WorkItem::class) ?? false);
    }

    public static function getNavigationLabel(): string
    {
        return __('Timeline');
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('work-timeline', 'Timeline: Help'),
        ];
    }

    public function projects(): array
    {
        return Project::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function currentProject(): ?Project
    {
        return $this->project !== null
            ? Project::query()->find($this->project)
            : Project::query()->orderBy('name')->first();
    }

    /**
     * Everything the view draws, with every date already turned into a percentage of the range.
     *
     * @return array{months: array<int, array{label: string, left: float, width: float}>, epics: array<int, array<string, mixed>>, releases: array<int, array<string, mixed>>, sprints: array<int, array<string, mixed>>, from: ?string, to: ?string}
     */
    public function timeline(): array
    {
        $project = $this->currentProject();

        if ($project === null) {
            return ['months' => [], 'epics' => [], 'releases' => [], 'sprints' => [], 'from' => null, 'to' => null];
        }

        $epics = WorkItem::query()
            ->where('project_id', $project->getKey())
            ->whereNull('archived_at')
            ->epics()
            ->with(['status', 'children' => fn ($q) => $q->whereNull('archived_at')->with('status')->orderBy('due_on')])
            ->orderBy('rank')
            ->get()
            ->map(fn (WorkItem $epic): ?array => $this->epicSpan($epic))
            ->filter()
            ->values();

        $releases = WorkRelease::query()
            ->where('project_id', $project->getKey())
            ->get()
            ->map(fn (WorkRelease $r): ?array => ($on = $r->released_on ?? $r->planned_on) === null ? null : [
                'name' => $r->name,
                'on' => $on,
                'released' => $r->isReleased(),
            ])
            ->filter()
            ->values();

        $sprints = WorkSprint::query()
            ->where('project_id', $project->getKey())
            ->whereNotNull('starts_on')
            ->whereNotNull('ends_on')
            ->orderBy('starts_on')
            ->get()
            ->map(fn (WorkSprint $s): array => ['name' => $s->name, 'from' => $s->starts_on, 'to' => $s->ends_on, 'state' => $s->state])
            ->values();

        $dates = collect()
            ->merge($epics->flatMap(fn (array $e): array => [$e['from'], $e['to']]))
            ->merge($releases->pluck('on'))
            ->merge($sprints->flatMap(fn (array $s): array => [$s['from'], $s['to']]))
            ->filter();

        if ($dates->isEmpty()) {
            return ['months' => [], 'epics' => [], 'releases' => [], 'sprints' => [], 'from' => null, 'to' => null];
        }

        // Whole months, so the heading reads as a calendar rather than as a ruler.
        $from = Carbon::parse($dates->min())->startOfMonth();
        $to = Carbon::parse($dates->max())->endOfMonth();
        $span = max(1, $from->diffInDays($to));

        $pct = fn (Carbon $date): float => round(max(0, min($span, $from->diffInDays($date, false))) / $span * 100, 2);

        $months = [];
        for ($m = $from->copy(); $m->lte($to); $m->addMonth()) {
            $end = $m->copy()->endOfMonth()->min($to);
            $months[] = ['label' => $m->format('M Y'), 'left' => $pct($m), 'width' => max(0.5, $pct($end) - $pct($m))];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'months' => $months,
            'epics' => $epics->map(fn (array $e): array => [
                ...$e,
                'left' => $pct($e['from']),
                'width' => max(0.8, $pct($e['to']) - $pct($e['from'])),
                'ticks' => array_map(fn (array $t): array => [...$t, 'left' => $pct($t['on'])], $e['ticks']),
            ])->all(),
            'releases' => $releases->map(fn (array $r): array => [...$r, 'left' => $pct($r['on'])])->all(),
            'sprints' => $sprints->map(fn (array $s): array => [...$s, 'left' => $pct($s['from']), 'width' => max(0.5, $pct($s['to']) - $pct($s['from']))])->all(),
        ];
    }

    /**
     * An Epic's span: from the earliest its children started to the latest anything is due.
     *
     * @return array{epic: WorkItem, from: Carbon, to: Carbon, percent: int, ticks: array<int, array{key: string, title: string, on: Carbon, done: bool}>}|null null when nothing about it is dated
     */
    private function epicSpan(WorkItem $epic): ?array
    {
        $children = $epic->children;

        $starts = $children->pluck('started_at')->filter()->push($epic->started_at)->filter();
        $dues = $children->pluck('due_on')->filter()->push($epic->due_on)->filter();
        $resolved = $children->pluck('resolved_at')->filter()->push($epic->resolved_at)->filter();

        $from = $starts->min() ?? $epic->created_at;
        $to = $dues->max() ?? $resolved->max();

        if ($from === null || $to === null) {
            return null;
        }

        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->endOfDay();

        if ($to->lt($from)) {
            $to = $from->copy()->endOfDay();
        }

        $progress = $epic->progress();

        return [
            'epic' => $epic,
            'from' => $from,
            'to' => $to,
            'percent' => $progress['percent'],
            'ticks' => $children
                ->filter(fn (WorkItem $c): bool => $c->due_on !== null)
                ->map(fn (WorkItem $c): array => ['key' => $c->key, 'title' => $c->title, 'on' => $c->due_on->copy()->startOfDay(), 'done' => $c->isDone()])
                ->values()
                ->all(),
        ];
    }
}
