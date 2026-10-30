<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Projects\Filament\Resources\Projects\Pages\ListProjects;
use App\Modules\Projects\Filament\Resources\Projects\Pages\ViewProject;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Filament\Pages\WorkCreatedVsResolved;
use App\Modules\Work\Filament\Pages\WorkCumulativeFlow;
use App\Modules\Work\Filament\Pages\WorkCycleTime;
use App\Modules\Work\Filament\Pages\WorkVelocity;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkSprint;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Services\Sprints;
use App\Modules\Work\Services\WorkItems;
use App\Modules\Work\Support\WorkReports;
use Database\Seeders\WorkWorkflowSeeder;
use Filament\Navigation\NavigationItem;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * What Work adds to the project page, the project list and the Reports hub —
 * docs/project-management-plan.md §7, and §13.3's rule that it arrives through a slot.
 *
 * The thing to prove about the tabs is the direction: they are there with the module and gone
 * without it, and `ProjectResource` names nothing of Work's either way.
 */
class WorkProjectPageTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'projectpage@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['projects', 'work'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
        $this->seed(WorkWorkflowSeeder::class);

        $this->project = Project::create([
            'code' => 'PRJ-WEB-01',
            'name' => 'The website',
            'status' => 'active',
            'work_workflow_id' => WorkWorkflow::default()->getKey(),
        ]);
    }

    private function item(array $attributes = []): WorkItem
    {
        return app(WorkItems::class)->create($this->project, ['title' => 'A thing', ...$attributes], auth()->user());
    }

    private function done(WorkItem $item): WorkItem
    {
        return $item->moveTo(WorkStatus::query()->where('name', 'Done')->firstOrFail(), null, 'Done');
    }

    /** @return array<int, string> */
    private function tabLabels(): array
    {
        $page = Livewire::test(ViewProject::class, ['record' => $this->project->getKey()])->assertSuccessful()->instance();

        return collect($page->getSubNavigation())
            ->map(fn (NavigationItem $item): string => (string) $item->getLabel())
            ->all();
    }

    public function test_the_project_page_has_the_work_tabs(): void
    {
        $this->assertSame(
            ['View', 'Edit', 'Board', 'Backlog', 'Sprints', 'Epics', 'Releases', 'Reports', 'Connections'],
            $this->tabLabels(),
        );
    }

    /** Without the module there are no tabs at all — not a bar reading "View · Edit" over an unchanged page. */
    public function test_without_the_work_module_the_project_page_has_no_tabs(): void
    {
        CompanyModule::query()->where('module', 'work')->update(['enabled' => false]);
        modules()->flush();

        $this->assertSame([], $this->tabLabels());
    }

    public function test_the_project_list_offers_the_three_work_columns_hidden_by_default(): void
    {
        $this->item(['title' => 'Open one']);
        $this->item(['title' => 'Open two']);
        $this->done($this->item(['title' => 'Finished this month']));
        app(Sprints::class)->start(WorkSprint::create([
            'project_id' => $this->project->getKey(),
            'name' => 'Sprint 14',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(13)->toDateString(),
        ]));

        $list = Livewire::test(ListProjects::class)->assertSuccessful();

        foreach (['work_open', 'work_done_this_month', 'work_sprint'] as $column) {
            $list->assertTableColumnExists($column);
            $this->assertTrue(
                $list->instance()->getTable()->getColumn($column)->isToggledHiddenByDefault(),
                "[{$column}] is a query per row, and should wait to be asked for",
            );
        }

        $table = $list->instance()->getTable();
        $project = $this->project->fresh();

        $this->assertSame(2, $table->getColumn('work_open')->record($project)->getState());
        $this->assertSame(1, $table->getColumn('work_done_this_month')->record($project)->getState());
        $this->assertSame('Sprint 14', $table->getColumn('work_sprint')->record($project)->getState());
    }

    /** The four reports draw, and say what the items say. */
    public function test_the_four_reports_render_and_read_the_items(): void
    {
        $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(10, 0));

        $this->item(['title' => 'Filed and waiting']);
        $started = $this->item(['title' => 'Started']);
        $started->moveTo(WorkStatus::query()->where('name', 'In progress')->firstOrFail());

        $this->travel(2)->days();
        $this->done($started);

        $reports = app(WorkReports::class);
        $asOf = now()->toDateString();

        $cycle = $reports->cycleTime($asOf);
        $this->assertSame('table', $cycle['kind']);
        $this->assertCount(1, $cycle['rows']);
        $this->assertSame(['The website', 'Task', '1', '2.0', '2.0', '—'], $cycle['rows'][0]);

        $flow = $reports->createdVsResolved($asOf);
        $this->assertSame(['The website', $flow['rows'][0][1], '2', '1', '−1'], $flow['rows'][0]);

        $cumulative = $reports->cumulativeFlow($asOf);
        $this->assertSame(['1', '—', '1', '2'], array_slice(end($cumulative['rows']), 2), 'one waiting, none in progress, one done');

        $this->assertSame([], $reports->velocity($asOf)['rows'], 'no sprint has closed');

        foreach ([
            WorkVelocity::class => 'Velocity',
            WorkCycleTime::class => 'Cycle & Lead Time',
            WorkCreatedVsResolved::class => 'Created vs Resolved',
            WorkCumulativeFlow::class => 'Cumulative Flow',
        ] as $page => $title) {
            Livewire::test($page)->assertSuccessful()->assertSee($title);
        }
    }
}
