<?php

namespace App\Modules\Work;

use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Projects\Filament\Resources\Projects\ProjectResource;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Filament\Pages\Backlog;
use App\Modules\Work\Filament\Pages\Board;
use App\Modules\Work\Filament\Pages\Epics;
use App\Modules\Work\Filament\Pages\WorkCreatedVsResolved;
use App\Modules\Work\Filament\Pages\WorkCumulativeFlow;
use App\Modules\Work\Filament\Pages\WorkCycleTime;
use App\Modules\Work\Filament\Pages\WorkVelocity;
use App\Modules\Work\Filament\Resources\WorkReleases\WorkReleaseResource;
use App\Modules\Work\Filament\Resources\WorkSprints\WorkSprintResource;
use App\Modules\Work\Models\WorkAttachment;
use App\Modules\Work\Models\WorkBoard;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkItemLink;
use App\Modules\Work\Models\WorkLabel;
use App\Modules\Work\Models\WorkRelease;
use App\Modules\Work\Models\WorkSprint;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Models\WorkWorkflowRule;
use App\Modules\Work\Policies\WorkAttachmentPolicy;
use App\Modules\Work\Policies\WorkBoardPolicy;
use App\Modules\Work\Policies\WorkItemLinkPolicy;
use App\Modules\Work\Policies\WorkItemPolicy;
use App\Modules\Work\Policies\WorkLabelPolicy;
use App\Modules\Work\Policies\WorkReleasePolicy;
use App\Modules\Work\Policies\WorkSprintPolicy;
use App\Modules\Work\Policies\WorkStatusPolicy;
use App\Modules\Work\Policies\WorkWorkflowPolicy;
use App\Modules\Work\Policies\WorkWorkflowRulePolicy;
use App\Modules\Work\Support\WorkReports;
use App\Support\CustomFieldSubjects;
use App\Support\ModuleMap;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use App\Support\ResourceContributions;
use Filament\Navigation\NavigationItem;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Policies, registries and scheduled work for the work module.
 *
 * Phase 1 is the policies; Phase 4 adds what this module contributes to the project page and to the
 * Reports hub. The webhook event types arrive in Phase 6 and the integration providers in Phase 7,
 * both here.
 */
class WorkServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        WorkWorkflow::class => WorkWorkflowPolicy::class,
        WorkStatus::class => WorkStatusPolicy::class,
        WorkWorkflowRule::class => WorkWorkflowRulePolicy::class,
        WorkItem::class => WorkItemPolicy::class,
        WorkLabel::class => WorkLabelPolicy::class,
        WorkAttachment::class => WorkAttachmentPolicy::class,
        WorkItemLink::class => WorkItemLinkPolicy::class,
        WorkBoard::class => WorkBoardPolicy::class,
        WorkSprint::class => WorkSprintPolicy::class,
        WorkRelease::class => WorkReleasePolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        /*
         * A work item takes custom fields from the day it exists — docs/project-management-plan.md §3.2.
         *
         * The bug form's "browser" and "environment" are the company's to add, and nothing here has
         * to be built for them: registering the subject is the whole of it. A module that waits for
         * somebody to ask is a module that ships a form nobody can adapt.
         */
        CustomFieldSubjects::register(ModuleMap::alias(WorkItem::class), 'Work Items');

        $this->contributeToProjects();
        $this->registerReports();
    }

    /**
     * What Work adds to the project page and the project list — docs/project-management-plan.md §7.
     *
     * `work` requires `projects`, so this direction is a declared dependency and free; the reverse
     * would be a cycle. So the tabs and the three columns are offered here, through the slots
     * `ProjectResource` holds open, and Projects never learns that Work exists (§13.3).
     *
     * Registered unconditionally, like every provider: one deployment serves every company and the
     * tenant is resolved per request, so the *licence* check is inside each closure, at render time.
     * A company without the module gets an empty slot, which `ProjectResource` reads as "no tabs".
     */
    private function contributeToProjects(): void
    {
        ResourceContributions::addSubNavigation(ProjectResource::class, 'work', function (Model $project): array {
            if (! $project instanceof Project || ! $this->worksHere()) {
                return [];
            }

            $id = (int) $project->getKey();
            $byProject = ['tableFilters' => ['project_id' => ['value' => $id]]];

            $items = [
                NavigationItem::make(__('Board'))->icon('heroicon-o-view-columns')->url(Board::getUrl(['project' => $id])),
                NavigationItem::make(__('Backlog'))->icon('heroicon-o-queue-list')->url(Backlog::getUrl(['project' => $id])),
                NavigationItem::make(__('Sprints'))->icon('heroicon-o-arrow-path')->url(WorkSprintResource::getUrl('index', $byProject)),
                NavigationItem::make(__('Epics'))->icon('heroicon-o-flag')->url(Epics::getUrl(['project' => $id])),
                NavigationItem::make(__('Releases'))->icon('heroicon-o-rocket-launch')->url(WorkReleaseResource::getUrl('index', $byProject)),
            ];

            // The hub, opened on the section the four work reports sit in. Only for somebody who may
            // read reports at all; the other tabs need no more than reading items.
            if (auth()->user()?->can('ReportView') ?? false) {
                $items[] = NavigationItem::make(__('Reports'))->icon('heroicon-o-chart-pie')->url(Reports::getUrl(['section' => 'Operations']));
            }

            return $items;
        });

        /*
         * Three computed columns, hidden by default — §7.
         *
         * Each is a query per row, which is why they are toggled off: the project list is read far
         * more often than these are wanted, and a figure nobody asked for should not be a cost on
         * every visit. When toggled on they are three small counts per project, which a list of
         * twenty projects can afford.
         */
        ResourceContributions::addTableColumn(ProjectResource::class, 'work_open', fn () => TextColumn::make('work_open')
            ->label(__('Open items'))
            ->alignEnd()
            ->visible(fn (): bool => $this->worksHere())
            ->state(fn (Project $record): int => WorkItem::query()->where('project_id', $record->getKey())->open()->count())
            ->toggleable(isToggledHiddenByDefault: true));

        ResourceContributions::addTableColumn(ProjectResource::class, 'work_done_this_month', fn () => TextColumn::make('work_done_this_month')
            ->label(__('Done this month'))
            ->alignEnd()
            ->visible(fn (): bool => $this->worksHere())
            ->state(fn (Project $record): int => WorkItem::query()
                ->where('project_id', $record->getKey())
                ->whereNull('archived_at')
                ->whereBetween('resolved_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count())
            ->toggleable(isToggledHiddenByDefault: true));

        ResourceContributions::addTableColumn(ProjectResource::class, 'work_sprint', fn () => TextColumn::make('work_sprint')
            ->label(__('Active sprint'))
            ->visible(fn (): bool => $this->worksHere())
            ->state(fn (Project $record): ?string => WorkSprint::query()
                ->where('project_id', $record->getKey())
                ->active()
                ->value('name'))
            ->placeholder('—')
            ->toggleable(isToggledHiddenByDefault: true));
    }

    /** Whether the company has the module and the reader may see a work item at all. */
    private function worksHere(): bool
    {
        return modules()->enabled('work') && (auth()->user()?->can('viewAny', WorkItem::class) ?? false);
    }

    /**
     * The four reports of §7, under *Operations* with the other delivery reports.
     *
     * Two registrations each, as every module does: the catalogue is what the hub lists, the
     * renderer is what draws it. Each page gates itself on `moduleIsAvailable()` and the hub filters
     * through `canAccess()`, so a company without the module sees no entry rather than four reports
     * that fail when opened.
     *
     * Burndown is deliberately not here — it is a line, and it lives on the sprint's own page. See
     * `ViewWorkSprint`.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register('Operations', WorkVelocity::class, 'What each closed sprint delivered, per project, and how much of it had carried in.');
        ReportCatalogue::register('Operations', WorkCycleTime::class, 'How long items take once started, and how long the asker waited, per project and type.');
        ReportCatalogue::register('Operations', WorkCreatedVsResolved::class, 'Whether each project is finishing work faster than it files it, week by week.');
        ReportCatalogue::register('Operations', WorkCumulativeFlow::class, 'Where every item stood at the end of each week — to do, in progress, done — per project.');

        ReportRenderers::register('WorkVelocity', fn (string $asOf): array => app(WorkReports::class)->velocity($asOf));
        ReportRenderers::register('WorkCycleTime', fn (string $asOf): array => app(WorkReports::class)->cycleTime($asOf));
        ReportRenderers::register('WorkCreatedVsResolved', fn (string $asOf): array => app(WorkReports::class)->createdVsResolved($asOf));
        ReportRenderers::register('WorkCumulativeFlow', fn (string $asOf): array => app(WorkReports::class)->cumulativeFlow($asOf));
    }
}
