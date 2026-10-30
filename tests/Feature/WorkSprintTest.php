<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Filament\Pages\Backlog;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkSprint;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Services\Sprints;
use App\Modules\Work\Services\WorkItems;
use Database\Seeders\WorkWorkflowSeeder;
use Livewire\Livewire;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Sprints and the backlog — docs/project-management-plan.md §6.1 and §6.2.
 *
 * The two things worth asserting are at the ends of a sprint's life: that starting one refuses what
 * it says it refuses, and that closing one puts every unfinished item exactly where it was told to
 * and records that it carried.
 */
class WorkSprintTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Project $project;

    private WorkWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'sprint@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['projects', 'work'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
        $this->seed(WorkWorkflowSeeder::class);

        $this->workflow = WorkWorkflow::default()->load('statuses');
        $this->project = Project::create([
            'code' => 'PRJ-WEB-01',
            'name' => 'The website',
            'status' => 'active',
            'work_workflow_id' => $this->workflow->getKey(),
        ]);
    }

    private function sprint(array $attributes = []): WorkSprint
    {
        return WorkSprint::create([
            'project_id' => $this->project->getKey(),
            'name' => 'Sprint 1',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(13)->toDateString(),
            ...$attributes,
        ]);
    }

    private function item(array $attributes = []): WorkItem
    {
        return app(WorkItems::class)->create($this->project, ['title' => 'A thing', ...$attributes], auth()->user());
    }

    private function statusNamed(string $name): WorkStatus
    {
        return WorkStatus::query()->where('name', $name)->firstOrFail();
    }

    public function test_a_sprint_needs_dates_before_it_can_run(): void
    {
        $sprint = $this->sprint(['starts_on' => null, 'ends_on' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs a start and an end');

        app(Sprints::class)->start($sprint);
    }

    /** Two sprints at once makes a burndown meaningless, so the second is refused. */
    public function test_only_one_sprint_runs_at_a_time(): void
    {
        app(Sprints::class)->start($this->sprint());
        $second = $this->sprint(['name' => 'Sprint 2']);

        try {
            app(Sprints::class)->start($second);
            $this->fail('a second sprint should not have started');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('is still running', $e->getMessage());
        }

        $this->assertSame(WorkSprint::FUTURE, $second->fresh()->state);
    }

    /**
     * Closing: the finished stay, the unfinished go where they were told, and the carry is recorded.
     */
    public function test_completing_moves_the_unfinished_and_records_the_carry(): void
    {
        $sprint = app(Sprints::class)->start($this->sprint());
        $next = $this->sprint(['name' => 'Sprint 2', 'starts_on' => now()->addDays(14)->toDateString(), 'ends_on' => now()->addDays(27)->toDateString()]);

        $finished = $this->item(['title' => 'Finished', 'sprint_id' => $sprint->getKey()]);
        $unfinished = $this->item(['title' => 'Not finished', 'sprint_id' => $sprint->getKey()]);
        $finished->moveTo($this->statusNamed('Done'), null, 'Done');

        app(Sprints::class)->complete($sprint, $next);

        $this->assertSame(WorkSprint::CLOSED, $sprint->fresh()->state);
        $this->assertNotNull($sprint->fresh()->closed_at);

        $this->assertSame((int) $sprint->getKey(), (int) $finished->fresh()->sprint_id, 'what was done stays where it was done');
        $this->assertSame(0, (int) $finished->fresh()->carried_over);

        $this->assertSame((int) $next->getKey(), (int) $unfinished->fresh()->sprint_id);
        $this->assertSame(1, (int) $unfinished->fresh()->carried_over, 'and it is marked as having carried');
    }

    public function test_completing_can_send_the_unfinished_back_to_the_backlog(): void
    {
        $sprint = app(Sprints::class)->start($this->sprint());
        $item = $this->item(['sprint_id' => $sprint->getKey()]);

        app(Sprints::class)->complete($sprint);

        $this->assertNull($item->fresh()->sprint_id, 'back to the backlog');
        $this->assertSame(1, (int) $item->fresh()->carried_over);
    }

    /** Carrying three times says three, which is the number planning should argue about. */
    public function test_the_carry_count_accumulates(): void
    {
        $item = $this->item();

        foreach (range(1, 3) as $round) {
            $sprint = app(Sprints::class)->start($this->sprint([
                'name' => "Sprint {$round}",
                'starts_on' => now()->addDays(($round - 1) * 14)->toDateString(),
                'ends_on' => now()->addDays($round * 14 - 1)->toDateString(),
            ]));

            $item->forceFill(['sprint_id' => $sprint->getKey()])->save();
            app(Sprints::class)->complete($sprint);
        }

        $this->assertSame(3, (int) $item->fresh()->carried_over);
    }

    public function test_unfinished_work_cannot_be_moved_into_the_sprint_being_closed(): void
    {
        $sprint = app(Sprints::class)->start($this->sprint());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the sprint being closed');

        app(Sprints::class)->complete($sprint, $sprint);
    }

    /**
     * Velocity counts what was resolved while the sprint actually ran.
     *
     * Between `started_at` and `closed_at` rather than the planned dates: a sprint started late
     * delivered what it delivered in the days it had.
     */
    public function test_velocity_counts_what_each_closed_sprint_delivered(): void
    {
        $sprint = app(Sprints::class)->start($this->sprint());

        $done = $this->item(['title' => 'Delivered', 'sprint_id' => $sprint->getKey(), 'estimate_points' => 5]);
        $done->moveTo($this->statusNamed('Done'), null, 'Done');

        $this->item(['title' => 'Not delivered', 'sprint_id' => $sprint->getKey(), 'estimate_points' => 3]);

        app(Sprints::class)->complete($sprint);

        $velocity = app(Sprints::class)->velocity($this->project);

        $this->assertCount(1, $velocity);
        $this->assertSame(5.0, $velocity->first()['points'], 'only what was finished counts');
        $this->assertSame(1, $velocity->first()['items']);
    }

    /** The backlog is open work in nobody's sprint, and putting it in one takes it off. */
    public function test_the_backlog_is_what_is_not_in_a_sprint(): void
    {
        $sprint = app(Sprints::class)->start($this->sprint());

        $waiting = $this->item(['title' => 'Waiting']);
        $committed = $this->item(['title' => 'Committed', 'sprint_id' => $sprint->getKey()]);

        $backlog = WorkItem::query()->backlog()->pluck('title')->all();

        $this->assertSame(['Waiting'], $backlog);
        $this->assertNotContains('Committed', $backlog);

        // And a finished item is not waiting either, whatever sprint it is in.
        $waiting->moveTo($this->statusNamed('Done'), null, 'Done');
        $this->assertSame([], WorkItem::query()->backlog()->pluck('title')->all());
    }

    /** Written at speed: a title and a type, and the rest takes defaults. */
    public function test_the_backlog_adds_an_item_from_a_title(): void
    {
        Livewire::test(Backlog::class, ['project' => $this->project->getKey()])
            ->set('newTitle', 'Export fails on Safari')
            ->set('type', 'Bug')
            ->call('add')
            ->assertSuccessful()
            ->assertSet('newTitle', '', 'the box clears, ready for the next one');

        $item = WorkItem::query()->firstWhere('title', 'Export fails on Safari');

        $this->assertNotNull($item);
        $this->assertSame('Bug', $item->type);
        $this->assertSame('WEB-1', $item->key);
        $this->assertSame('To do', $item->status->name);
    }

    public function test_the_backlog_moves_a_handful_into_a_sprint_at_once(): void
    {
        $sprint = $this->sprint();
        $one = $this->item(['title' => 'One']);
        $two = $this->item(['title' => 'Two']);
        $three = $this->item(['title' => 'Three']);

        Livewire::test(Backlog::class, ['project' => $this->project->getKey()])
            ->set('selected', [$one->getKey(), $two->getKey()])
            ->call('moveToSprint', $sprint->getKey())
            ->assertSet('selected', []);

        $this->assertSame((int) $sprint->getKey(), (int) $one->fresh()->sprint_id);
        $this->assertSame((int) $sprint->getKey(), (int) $two->fresh()->sprint_id);
        $this->assertNull($three->fresh()->sprint_id, 'and the one nobody ticked stayed put');
    }

    /** Dragging a backlog row rewrites that row, not the ones under it. */
    public function test_reordering_the_backlog_moves_one_row(): void
    {
        $first = $this->item(['title' => 'First']);
        $second = $this->item(['title' => 'Second']);
        $third = $this->item(['title' => 'Third']);

        $ranksBefore = [$first->fresh()->rank, $second->fresh()->rank];

        Livewire::test(Backlog::class, ['project' => $this->project->getKey()])
            ->call('reorder', $third->getKey(), $first->getKey(), $second->getKey());

        $this->assertSame(
            ['First', 'Third', 'Second'],
            WorkItem::query()->backlog()->orderBy('rank')->pluck('title')->all(),
        );

        $this->assertSame($ranksBefore, [$first->fresh()->rank, $second->fresh()->rank],
            'the neighbours were not renumbered');
    }

    /** A Scrum board with nothing running shows nothing, which is the truth. */
    public function test_a_scrum_board_between_sprints_is_empty(): void
    {
        $board = \App\Modules\Work\Models\WorkBoard::create([
            'project_id' => $this->project->getKey(),
            'name' => 'Scrum',
            'kind' => \App\Modules\Work\Models\WorkBoard::SCRUM,
            'columns' => [['label' => 'To do', 'status_ids' => [$this->statusNamed('To do')->getKey()]]],
        ]);

        $this->item(['title' => 'In the backlog']);

        $columns = app(\App\Modules\Work\Services\Boards::class)->columns($board);
        $this->assertSame(0, $columns[0]['total'], 'nothing is committed, so nothing is on the board');

        $sprint = app(Sprints::class)->start($this->sprint());
        $this->item(['title' => 'Committed', 'sprint_id' => $sprint->getKey()]);

        $columns = app(\App\Modules\Work\Services\Boards::class)->columns($board);
        $this->assertSame(['Committed'], array_column($columns[0]['lanes'][0]['cards'], 'title'));
    }
}
