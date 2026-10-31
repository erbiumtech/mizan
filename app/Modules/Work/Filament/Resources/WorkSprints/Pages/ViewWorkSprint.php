<?php

namespace App\Modules\Work\Filament\Resources\WorkSprints\Pages;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Support\HelpAction;
use App\Modules\Work\Filament\Resources\WorkSprints\WorkSprintResource;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Services\Sprints;
use App\Modules\Work\Services\WorkTime;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One sprint: its goal, how it is burning down, and what the team has managed before.
 *
 * **The burndown and the velocity live here rather than in the Reports hub**, and that is a
 * deviation from the plan worth stating. §14 Phase 3 put burndown in the report catalogue; the
 * catalogue's shape is a tabular statement with tiles and totals, and a burndown is a line. Forcing
 * one through would mean inventing a chart shape in the reporting layer — a project of its own —
 * and the two screens that actually want these numbers are planning and the standup, both of which
 * open the sprint rather than the hub.
 */
class ViewWorkSprint extends ViewRecord
{
    protected static string $resource = WorkSprintResource::class;

    protected string $view = 'filament.work.sprint';

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->record->goal;
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('work-sprints', 'Sprints: Help'),
            ReplicateRecordAction::make(),
        ];
    }

    /** @return array<int, array{day: string, remaining: float, ideal: float}> */
    public function burndown(): array
    {
        return app(Sprints::class)->burndown($this->record);
    }

    /** @return array<int, array<string, mixed>> */
    public function velocity(): array
    {
        return $this->record->project === null
            ? []
            : app(Sprints::class)->velocity($this->record->project)->all();
    }

    /**
     * Estimate against logged, in sum, and what the logged hours cost — §9.
     *
     * @return array{estimate: ?int, logged: int, remaining: ?int, cost: ?float, uncosted: int, entries: int}
     */
    public function time(): array
    {
        return app(WorkTime::class)->summaryForSprint($this->record);
    }

    public function timeIsAvailable(): bool
    {
        return app(WorkTime::class)->isAvailable();
    }

    /** What is in it, and how much of it is finished. */
    public function progress(): array
    {
        $items = WorkItem::query()->where('sprint_id', $this->record->getKey())->with('status')->get();

        $done = $items->filter(fn (WorkItem $i): bool => $i->status?->category === WorkStatus::DONE);

        return [
            'items' => $items->count(),
            'done' => $done->count(),
            'points' => (float) $items->sum(fn (WorkItem $i): float => (float) ($i->estimate_points ?? 0)),
            'points_done' => (float) $done->sum(fn (WorkItem $i): float => (float) ($i->estimate_points ?? 0)),
            'carried' => $items->filter(fn (WorkItem $i): bool => $i->carried_over > 0)->count(),
        ];
    }
}
