<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Filament\Pages\Board;
use App\Modules\Work\Models\WorkBoard;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkItemLink;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Models\WorkWorkflowRule;
use App\Modules\Work\Services\Boards;
use App\Modules\Work\Services\WorkItems;
use Database\Seeders\WorkWorkflowSeeder;
use Livewire\Livewire;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The board — docs/project-management-plan.md §5.
 *
 * The drag itself is SortableJS and is not testable here. What is asserted is everything the drop
 * calls into: which column a card lands in, that a refused move leaves the item exactly where it
 * was, and that the two things allowed to refuse one both do.
 */
class WorkBoardTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Project $project;

    private WorkWorkflow $workflow;

    private WorkBoard $board;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'board@test.local'));
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
            'code' => 'PRJ-APP-01',
            'name' => 'The app',
            'status' => 'active',
            'work_workflow_id' => $this->workflow->getKey(),
        ]);
        $this->board = WorkBoard::defaultFor($this->project, $this->workflow);
    }

    private function item(array $attributes = []): WorkItem
    {
        return app(WorkItems::class)->create($this->project, ['title' => 'A thing', ...$attributes], auth()->user());
    }

    private function statusNamed(string $name): WorkStatus
    {
        return WorkStatus::query()->where('name', $name)->firstOrFail();
    }

    /** One column per status, in the workflow's order, made on first view. */
    public function test_a_project_gets_a_column_for_every_status_without_anybody_making_one(): void
    {
        $this->assertSame(
            ['To do', 'In progress', 'In review', 'Done'],
            array_column($this->board->columns, 'label'),
        );
    }

    public function test_cards_land_in_the_column_holding_their_status(): void
    {
        $waiting = $this->item(['title' => 'Still waiting']);
        $started = $this->item(['title' => 'Under way']);
        $started->moveTo($this->statusNamed('In progress'));

        $columns = app(Boards::class)->columns($this->board);

        $this->assertSame(['Still waiting'], array_column($columns[0]['lanes'][0]['cards'], 'title'));
        $this->assertSame(['Under way'], array_column($columns[1]['lanes'][0]['cards'], 'title'));
        $this->assertSame(1, $columns[0]['total']);
    }

    /** Dropping into a column moves the item, through the same door the form uses. */
    public function test_a_drop_moves_the_item(): void
    {
        $item = $this->item();

        app(Boards::class)->move($this->board, $item, 1, null, null, auth()->user());

        $this->assertSame('In progress', $item->fresh()->status->name);
        $this->assertNotNull($item->fresh()->started_at, 'and the stamps happen, because moveTo did it');
    }

    /** Dragging within a column re-ranks and nothing else. */
    public function test_a_drop_in_the_same_column_only_reorders(): void
    {
        $first = $this->item(['title' => 'First']);
        $second = $this->item(['title' => 'Second']);
        $third = $this->item(['title' => 'Third']);

        $this->assertSame(['First', 'Second', 'Third'], $this->titlesIn(0));

        // Third, dropped between First and Second.
        app(Boards::class)->move($this->board, $third, 0, $first->getKey(), $second->getKey(), auth()->user());

        $this->assertSame(['First', 'Third', 'Second'], $this->titlesIn(0));
        $this->assertSame('To do', $third->fresh()->status->name, 'it never changed status');
        $this->assertNull($third->fresh()->started_at);
    }

    /**
     * A limit is a limit.
     *
     * The seventh card into a column of six is refused rather than accepted with the column turning
     * red, because a limit that lets you past teaches people to ignore it.
     */
    public function test_a_column_at_its_limit_refuses_the_drop(): void
    {
        $this->board->update(['wip_limits' => ['1' => 2]]);

        $one = $this->item(['title' => 'One']);
        $two = $this->item(['title' => 'Two']);
        $three = $this->item(['title' => 'Three']);

        app(Boards::class)->move($this->board, $one, 1, null, null, auth()->user());
        app(Boards::class)->move($this->board, $two, 1, null, null, auth()->user());

        try {
            app(Boards::class)->move($this->board, $three, 1, null, null, auth()->user());
            $this->fail('the third card should have been refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('at its limit of 2', $e->getMessage());
        }

        $this->assertSame('To do', $three->fresh()->status->name, 'and it stayed where it was');
    }

    /** Already in the column, dragged up or down: the limit is about entering, not about being there. */
    public function test_reordering_inside_a_full_column_is_still_allowed(): void
    {
        $this->board->update(['wip_limits' => ['1' => 2]]);

        $one = $this->item(['title' => 'One']);
        $two = $this->item(['title' => 'Two']);
        app(Boards::class)->move($this->board, $one, 1, null, null, auth()->user());
        app(Boards::class)->move($this->board, $two, 1, null, null, auth()->user());

        app(Boards::class)->move($this->board, $two, 1, null, $one->getKey(), auth()->user());

        $this->assertSame(['Two', 'One'], $this->titlesIn(1), 'a full column can still be ordered');
    }

    /** The workflow refuses through the same path the form does. */
    public function test_a_workflow_rule_refuses_a_drop(): void
    {
        WorkWorkflowRule::create([
            'work_workflow_id' => $this->workflow->getKey(),
            'kind' => WorkWorkflowRule::REQUIRES_ASSIGNEE,
            'work_status_id' => $this->statusNamed('In progress')->getKey(),
        ]);

        $item = $this->item();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs somebody assigned');

        app(Boards::class)->move($this->board, $item, 1, null, null, auth()->user());
    }

    /** Blocked is computed once for the page, not once per card. */
    public function test_a_card_says_when_something_is_holding_it_up(): void
    {
        $blocker = $this->item(['title' => 'Comes first']);
        $blocked = $this->item(['title' => 'Waits for it']);

        WorkItemLink::create([
            'from_item_id' => $blocker->getKey(),
            'to_item_id' => $blocked->getKey(),
            'kind' => WorkItemLink::BLOCKS,
        ]);

        $cards = collect(app(Boards::class)->columns($this->board)[0]['lanes'][0]['cards'])
            ->keyBy('title');

        $this->assertTrue($cards['Waits for it']['blocked']);
        $this->assertFalse($cards['Comes first']['blocked']);
    }

    /** A status in no column is named on the screen rather than quietly swallowing cards. */
    public function test_a_status_no_column_shows_is_reported(): void
    {
        WorkStatus::create([
            'work_workflow_id' => $this->workflow->getKey(),
            'name' => 'On hold',
            'category' => WorkStatus::TODO,
            'sort' => 15,
        ]);

        Livewire::test(Board::class, ['project' => $this->project->getKey()])
            ->assertSuccessful()
            ->assertSee('Not on this board')
            ->assertSee('On hold');
    }

    public function test_the_board_renders_its_cards(): void
    {
        $this->item(['title' => 'Visible on the board']);

        Livewire::test(Board::class, ['project' => $this->project->getKey()])
            ->assertSuccessful()
            ->assertSee('Visible on the board')
            ->assertSee('APP-1');
    }

    /**
     * The Material board's header pieces: the project under "Work" in the breadcrumbs, a column's
     * status category for its dot, a count chip reading "n / limit", and List | Board | Timeline
     * links for the same project.
     */
    public function test_the_board_draws_its_material_header_and_count_chips(): void
    {
        $this->board->update(['wip_limits' => ['1' => 3]]);
        $this->item(['title' => 'Counted']);

        $columns = app(Boards::class)->columns($this->board);
        $this->assertSame(WorkStatus::TODO, $columns[0]['category']);

        $page = Livewire::test(Board::class, ['project' => $this->project->getKey()])
            ->assertSuccessful()
            ->assertSee('fi-board-column-dot', false);

        $links = collect($page->instance()->viewSwitch())->keyBy('label');
        $this->assertTrue($links['Board']['current']);
        $this->assertStringContainsString('project='.$this->project->getKey(), (string) $links['Timeline']['url']);
        $this->assertContains($this->project->name, $page->instance()->getBreadcrumbs());
    }

    /** A refused drop reaches the screen as a message rather than as a card in the wrong place. */
    public function test_the_screen_reports_a_refusal_and_leaves_the_card(): void
    {
        $this->board->update(['wip_limits' => ['1' => 1]]);

        $first = $this->item(['title' => 'First']);
        $second = $this->item(['title' => 'Second']);
        app(Boards::class)->move($this->board, $first, 1, null, null, auth()->user());

        Livewire::test(Board::class, ['project' => $this->project->getKey()])
            ->call('moveCard', $second->getKey(), 1)
            ->assertNotified();

        $this->assertSame('To do', $second->fresh()->status->name);
    }

    /** Swimlanes are a group-by and nothing more. */
    public function test_lanes_group_the_cards(): void
    {
        $this->board->update(['swimlane' => 'priority']);

        $this->item(['title' => 'Urgent one', 'priority' => 'Highest']);
        $this->item(['title' => 'Ordinary one', 'priority' => 'Medium']);

        $lanes = app(Boards::class)->columns($this->board)[0]['lanes'];

        $this->assertSame(['Highest', 'Medium'], array_column($lanes, 'label'));
        $this->assertSame(['Urgent one'], array_column($lanes[0]['cards'], 'title'));
    }

    /** @return array<int, string> */
    private function titlesIn(int $column): array
    {
        return array_column(
            app(Boards::class)->columns($this->board)[$column]['lanes'][0]['cards'],
            'title',
        );
    }
}
