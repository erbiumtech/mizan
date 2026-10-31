<?php

namespace App\Modules\Work;

use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Projects\Filament\Resources\Projects\ProjectResource;
use App\Modules\Projects\Models\Project;
use App\Modules\Timesheets\Filament\Resources\TimesheetEntries\TimesheetEntryResource;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Modules\Work\Console\Commands\ImportTrello;
use App\Modules\Work\Console\Commands\PullTimeEntries;
use App\Modules\Work\Console\Commands\SendWorkDigest;
use App\Modules\Work\Events\ReleaseReleased;
use App\Modules\Work\Events\SprintCompleted;
use App\Modules\Work\Events\SprintStarted;
use App\Modules\Work\Events\WorkItemAssigned;
use App\Modules\Work\Events\WorkItemCommented;
use App\Modules\Work\Events\WorkItemCreated;
use App\Modules\Work\Events\WorkItemMoved;
use App\Modules\Work\Events\WorkItemResolved;
use App\Modules\Work\Filament\Pages\Backlog;
use App\Modules\Work\Filament\Pages\Board;
use App\Modules\Work\Filament\Pages\Epics;
use App\Modules\Work\Filament\Pages\Timeline;
use App\Modules\Work\Filament\Pages\WorkCreatedVsResolved;
use App\Modules\Work\Filament\Pages\WorkCumulativeFlow;
use App\Modules\Work\Filament\Pages\WorkCycleTime;
use App\Modules\Work\Filament\Pages\WorkTimeBySource;
use App\Modules\Work\Filament\Pages\WorkVelocity;
use App\Modules\Work\Filament\RelationManagers\ChannelSubscriptionsRelationManager;
use App\Modules\Work\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use App\Modules\Work\Filament\Resources\WorkReleases\WorkReleaseResource;
use App\Modules\Work\Filament\Resources\WorkSprints\WorkSprintResource;
use App\Modules\Work\Filament\Settings\WorkDefaultsSettingsSection;
use App\Modules\Work\Imports\JiraCsvImporter;
use App\Modules\Work\Integrations\Bitbucket\BitbucketProvider;
use App\Modules\Work\Integrations\Clockify\ClockifyProvider;
use App\Modules\Work\Integrations\GitHub\GitHubProvider;
use App\Modules\Work\Integrations\GitLab\GitLabProvider;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\IntegrationEvent;
use App\Modules\Work\Models\IntegrationIdentity;
use App\Modules\Work\Models\WorkAttachment;
use App\Modules\Work\Models\WorkBoard;
use App\Modules\Work\Models\WorkChannelSubscription;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkItemBranch;
use App\Modules\Work\Models\WorkItemCommit;
use App\Modules\Work\Models\WorkItemLink;
use App\Modules\Work\Models\WorkItemPullRequest;
use App\Modules\Work\Models\WorkLabel;
use App\Modules\Work\Models\WorkRelease;
use App\Modules\Work\Models\WorkRepository;
use App\Modules\Work\Models\WorkSprint;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkTimeProject;
use App\Modules\Work\Models\WorkTimeTask;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Models\WorkWorkflowRule;
use App\Modules\Work\Policies\IntegrationConnectionPolicy;
use App\Modules\Work\Policies\IntegrationEventPolicy;
use App\Modules\Work\Policies\IntegrationIdentityPolicy;
use App\Modules\Work\Policies\WorkAttachmentPolicy;
use App\Modules\Work\Policies\WorkBoardPolicy;
use App\Modules\Work\Policies\WorkChannelSubscriptionPolicy;
use App\Modules\Work\Policies\WorkItemBranchPolicy;
use App\Modules\Work\Policies\WorkItemCommitPolicy;
use App\Modules\Work\Policies\WorkItemLinkPolicy;
use App\Modules\Work\Policies\WorkItemPolicy;
use App\Modules\Work\Policies\WorkItemPullRequestPolicy;
use App\Modules\Work\Policies\WorkLabelPolicy;
use App\Modules\Work\Policies\WorkReleasePolicy;
use App\Modules\Work\Policies\WorkRepositoryPolicy;
use App\Modules\Work\Policies\WorkSprintPolicy;
use App\Modules\Work\Policies\WorkStatusPolicy;
use App\Modules\Work\Policies\WorkTimeProjectPolicy;
use App\Modules\Work\Policies\WorkTimeTaskPolicy;
use App\Modules\Work\Policies\WorkWorkflowPolicy;
use App\Modules\Work\Policies\WorkWorkflowRulePolicy;
use App\Modules\Work\Services\BoardSettings;
use App\Modules\Work\Services\WorkTime;
use App\Modules\Work\Support\ChannelPosts;
use App\Modules\Work\Support\SyncedTimesheetRows;
use App\Modules\Work\Support\TimeTaskOnProgress;
use App\Modules\Work\Support\WorkReports;
use App\Modules\Work\Support\WorkWatching;
use App\Modules\Work\Support\WorkWebhookPayloads;
use App\Support\ApiAbilities;
use App\Support\CsvImporters;
use App\Support\CustomFieldSubjects;
use App\Support\Integrations;
use App\Support\ModuleMap;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use App\Support\ResourceContributions;
use App\Support\SettingsSections;
use App\Support\Webhooks;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Navigation\NavigationItem;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
        IntegrationConnection::class => IntegrationConnectionPolicy::class,
        WorkRepository::class => WorkRepositoryPolicy::class,
        IntegrationEvent::class => IntegrationEventPolicy::class,
        IntegrationIdentity::class => IntegrationIdentityPolicy::class,
        WorkItemCommit::class => WorkItemCommitPolicy::class,
        WorkItemBranch::class => WorkItemBranchPolicy::class,
        WorkItemPullRequest::class => WorkItemPullRequestPolicy::class,
        WorkTimeProject::class => WorkTimeProjectPolicy::class,
        WorkTimeTask::class => WorkTimeTaskPolicy::class,
        WorkChannelSubscription::class => WorkChannelSubscriptionPolicy::class,
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
        $this->contributeWorkflowToProjects();
        $this->contributeToTimesheets();
        $this->registerReports();

        // Who hears what — §10. Watching rules on the models, the eight webhook types, the company
        // defaults block, and the digest command the schedule in routes/console.php runs.
        WorkWatching::register();
        $this->registerWebhooks();
        SettingsSections::register('work.defaults', WorkDefaultsSettingsSection::class, 36, 'company');
        $this->commands([SendWorkDigest::class, PullTimeEntries::class]);

        /*
         * The providers — §8. Three code hosts and one time tracker; the registry is shared code and
         * holds a class name, the drivers are this module's. GitHub first because it is where most
         * of this company's own code lives.
         */
        Integrations::register(GitHubProvider::KEY, 'GitHub', Integrations::CODE, GitHubProvider::class, sort: 10);
        Integrations::register(BitbucketProvider::KEY, 'Bitbucket', Integrations::CODE, BitbucketProvider::class, sort: 20);
        Integrations::register(GitLabProvider::KEY, 'GitLab', Integrations::CODE, GitLabProvider::class, sort: 30);
        Integrations::register(ClockifyProvider::KEY, 'Clockify', Integrations::TIME, ClockifyProvider::class, sort: 40);

        // The tracker's task made as the item starts, where a project asks for it — §8.8.
        TimeTaskOnProgress::register();

        /*
         * Chat — §8.7. A project posts a line per chosen event to a Slack channel or a Teams webhook.
         * The subscriptions live on the project page as a tab, through the slot, and the relation is
         * added to the Project model here, so Projects names nothing of this module's.
         */
        Project::resolveRelationUsing('workChannelSubscriptions', fn (Project $project) => $project->hasMany(WorkChannelSubscription::class));
        ResourceContributions::addRelationManager(ProjectResource::class, ChannelSubscriptionsRelationManager::class);
        ChannelPosts::register();

        // Imports — §8.9. Jira through the CSV page; Trello's JSON through a command.
        CsvImporters::register('jira', JiraCsvImporter::class, 70);
        $this->commands([ImportTrello::class]);

        // The API — §11. A key has to be issued with the `work` ability to reach a board.
        ApiAbilities::register('work', 'Work items and boards');
        $this->loadRoutesFrom(__DIR__.'/routes/api.php');

        // The inbound hook endpoint — §8.3. Public, tenant resolved by its own middleware.
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
    }

    /**
     * The eight outgoing webhooks — docs/project-management-plan.md §10.4.
     *
     * Each with a named payload from `WorkWebhookPayloads`: the key, the project key, the status and
     * its category, the assignee's email — and never the description, which is the one field a
     * subscriber should fetch rather than be sent. Core wires a listener per type once every
     * provider has booted, so registering here is the whole of the work.
     */
    private function registerWebhooks(): void
    {
        $item = fn (object $event): array => WorkWebhookPayloads::item($event->item);

        Webhooks::register('work_item.created', 'A work item was filed', WorkItemCreated::class, $item, sort: 60);
        Webhooks::register('work_item.moved', 'A work item changed status', WorkItemMoved::class, fn (object $event): array => [
            ...WorkWebhookPayloads::item($event->item),
            'from' => $event->from?->name,
            'from_category' => $event->from?->category,
        ], sort: 61);
        Webhooks::register('work_item.assigned', 'A work item was assigned', WorkItemAssigned::class, $item, sort: 62);
        Webhooks::register('work_item.resolved', 'A work item was resolved', WorkItemResolved::class, $item, sort: 63);
        Webhooks::register('work_item.commented', 'Somebody commented on a work item', WorkItemCommented::class, fn (object $event): array => [
            ...WorkWebhookPayloads::item($event->item),
            'comment_id' => (int) $event->comment->getKey(),
            'commented_by' => $event->comment->user?->email,
        ], sort: 64);
        Webhooks::register('sprint.started', 'A sprint started', SprintStarted::class, fn (object $event): array => WorkWebhookPayloads::sprint($event->sprint), sort: 65);
        Webhooks::register('sprint.completed', 'A sprint was completed', SprintCompleted::class, fn (object $event): array => [
            ...WorkWebhookPayloads::sprint($event->sprint),
            'unfinished_moved_to' => $event->movedTo?->name,
        ], sort: 66);
        Webhooks::register('release.released', 'A release went out', ReleaseReleased::class, fn (object $event): array => WorkWebhookPayloads::release($event->release), sort: 67);
    }

    /**
     * What Work adds to the timesheet — docs/project-management-plan.md §9.
     *
     * `work` does **not** require `timesheets`: a board is worth having without a ledger of hours, and
     * a timesheet without a board. So this is the guarded shape (`ModuleBoundaryTest` KNOWN_COUPLINGS):
     * the picker is offered only when the entry's project has items, the row guard only fires on rows
     * with a non-manual source, and a company without one module or the other sees neither.
     *
     * Three things: the item picker on the entry form, a marker on rows that came from a sync, and
     * the rule that such a row is edited where it was made.
     */
    private function contributeToTimesheets(): void
    {
        // Offered only when the entry's project has items: a timesheet in a company that runs no
        // board should not grow a field that is always empty.
        ResourceContributions::addFormComponent(TimesheetEntryResource::class, 'work_item', fn () => Select::make('work_item_id')
            ->label(__('Work item'))
            ->options(fn (Get $get, ?TimesheetEntry $record): array => app(WorkTime::class)
                ->pickableItems($get('project_id'), $record?->getAttribute('work_item_id')))
            ->searchable()
            ->placeholder(__('None'))
            ->visible(fn (Get $get): bool => modules()->enabled('work')
                && $get('project_id') !== null
                && WorkItem::query()->where('project_id', $get('project_id'))->exists())
            ->helperText(__('Which piece of work this hour went on. The item shows it against its estimate.')));

        // Says where a synced row came from, in the row. Disabled rather than hidden, because a
        // button that does nothing but explain is the explanation.
        ResourceContributions::addRecordAction(TimesheetEntryResource::class, 'work_source', fn () => Action::make('work_source')
            ->label(fn (TimesheetEntry $record): string => WorkTime::SOURCES[(string) $record->getAttribute('source')] ?? (string) $record->getAttribute('source'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->disabled()
            ->tooltip(__('This row was synced in and is edited where it was made.'))
            ->visible(fn (TimesheetEntry $record): bool => ! WorkTime::isEditable($record)));

        SyncedTimesheetRows::register();
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
                NavigationItem::make(__('Timeline'))->icon('heroicon-o-calendar-days')->url(Timeline::getUrl(['project' => $id])),
            ];

            // The hub, opened on the section the work reports sit in. Only for somebody who may
            // read reports at all; the other tabs need no more than reading items.
            if (auth()->user()?->can('ReportView') ?? false) {
                $items[] = NavigationItem::make(__('Reports'))->icon('heroicon-o-chart-pie')->url(Reports::getUrl(['section' => 'Operations']));
            }

            // §7's Repositories tab, renamed Connections once a time provider exists (§8.8): the
            // repositories and tracker projects are linked from the connection's side, so the tab
            // opens the integrations list. Only for the administrator who may manage one.
            if (auth()->user()?->can('IntegrationManage') ?? false) {
                $items[] = NavigationItem::make(__('Connections'))->icon('heroicon-o-link')->url(IntegrationConnectionResource::getUrl('index'));
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

    /**
     * Which workflow a project's boards are drawn from — the one Work column on the project that
     * Projects could not offer itself, because choosing it is only safe with Work's rule behind it.
     *
     * The field is contributed to the project form, offered only where the company has the module.
     * Its rule refuses a workflow the project's items are not in, with the sentence `BoardSettings`
     * gives; the model guard refuses the same change made any other way, and once a change has gone
     * through, the project's boards are redrawn from the new workflow. See `BoardSettings` for why
     * the rule is the strict one.
     */
    private function contributeWorkflowToProjects(): void
    {
        ResourceContributions::addFormComponent(ProjectResource::class, 'work_workflow', fn () => Section::make(__('Workflow'))
            ->description(__('The statuses this project\'s work items move through, and so the columns its boards start with.'))
            ->visible(fn (): bool => modules()->enabled('work'))
            ->schema([
                Select::make('work_workflow_id')
                    ->label(__('Workflow'))
                    ->options(fn (): array => WorkWorkflow::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder(__('Company default'))
                    ->native(false)
                    ->nullable()
                    ->rules([
                        fn (?Project $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            if ($record === null) {
                                return;
                            }

                            $problem = app(BoardSettings::class)->workflowChangeProblem($record, filled($value) ? (int) $value : null);

                            if ($problem !== null) {
                                $fail($problem);
                            }
                        },
                    ])
                    ->helperText(__('Empty uses the company default. It can be changed only while none of the project\'s items is in a status the new workflow lacks, and changing it redraws the project\'s boards.')),
            ]));

        Project::updating(function (Project $project): void {
            if ($project->isDirty('work_workflow_id')) {
                app(BoardSettings::class)->assertWorkflowMayChange($project);
            }
        });

        Project::updated(function (Project $project): void {
            if ($project->wasChanged('work_workflow_id')) {
                $previous = $project->getOriginal('work_workflow_id');
                app(BoardSettings::class)->rebuildBoards($project, $previous !== null ? (int) $previous : null);
            }
        });
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

        // §9's sixth report, built with §8.8: the reconciliation that makes `either` allowable.
        ReportCatalogue::register('Operations', WorkTimeBySource::class, 'Hours logged here against hours synced from a tracker, per person, with the days that have both.');
        ReportRenderers::register('WorkTimeBySource', fn (string $asOf): array => app(WorkReports::class)->timeBySource($asOf));
    }
}
