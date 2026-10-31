<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Comment;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Filament\Pages\Board;
use App\Modules\Work\Models\WorkBoard;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkItemLink;
use App\Modules\Work\Models\WorkLabel;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Services\WorkItemPanel;
use App\Modules\Work\Services\WorkItems;
use Database\Seeders\WorkWorkflowSeeder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The item panel a board card opens, and the board's space layout around it.
 *
 * Every write the panel offers goes through a door something else already uses, so what is asserted
 * is that the door is the one used: a status change refused by the workflow is refused here too,
 * a subtask is made by `WorkItems::create()` with its key, the comment lands on the item's thread.
 */
class WorkItemPanelTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Project $project;

    private WorkWorkflow $workflow;

    private Employee $me;

    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->makeUser('Administrator', 'panel@test.local');
        $this->actingAs($user);
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
            'code' => 'PRJ-PNL-01',
            'name' => 'The app',
            'status' => 'active',
            'work_workflow_id' => $this->workflow->getKey(),
        ]);
        WorkBoard::defaultFor($this->project, $this->workflow);

        $this->me = Employee::create([
            'employee_id' => 'EMP-PNL',
            'name' => 'Sana Malik',
            'gender' => 'Female',
            'is_active' => true,
            'user_id' => $user->getKey(),
        ]);
    }

    private function item(array $attributes = []): WorkItem
    {
        return app(WorkItems::class)->create($this->project, ['title' => 'A thing', ...$attributes], auth()->user());
    }

    private function board(?WorkItem $open = null)
    {
        return Livewire::test(Board::class, array_filter([
            'project' => $this->project->getKey(),
            'selected' => $open?->getKey(),
        ]));
    }

    public function test_a_card_opens_the_panel_with_the_item_in_it(): void
    {
        $item = $this->item(['title' => 'Export fails on Safari', 'description' => 'Only when the **month** is empty.']);

        $this->board()
            ->assertDontSee('fi-wip-title', false)
            ->call('openItem', $item->getKey())
            ->assertSet('selected', $item->getKey())
            ->assertSee('fi-wip-title', false)
            ->assertSee('Key details')
            ->assertSee('<strong>month</strong>', false)
            ->call('closeItem')
            ->assertSet('selected', null)
            ->assertDontSee('fi-wip-title', false);
    }

    /** The panel reads a dozen relations; with lazy loading off it must still draw, for two items' worth. */
    public function test_the_panel_draws_without_lazy_loading(): void
    {
        $parent = $this->item(['title' => 'Checkout redesign', 'type' => WorkItem::TYPE_EPIC]);
        $item = $this->item(['title' => 'Card form', 'parent_id' => $parent->getKey(), 'assignee_employee_id' => $this->me->getKey()]);
        $other = $this->item(['title' => 'Payment page']);
        $this->item(['title' => 'Validate the expiry', 'type' => WorkItem::TYPE_SUBTASK, 'parent_id' => $item->getKey()]);
        $this->item(['title' => 'Validate the CVC', 'type' => WorkItem::TYPE_SUBTASK, 'parent_id' => $item->getKey()]);
        WorkItemLink::create(['from_item_id' => $item->getKey(), 'to_item_id' => $other->getKey(), 'kind' => WorkItemLink::BLOCKS]);
        WorkItemLink::create(['from_item_id' => $parent->getKey(), 'to_item_id' => $item->getKey(), 'kind' => WorkItemLink::RELATES_TO]);
        $labels = collect(['OCI', 'SCI'])->map(fn (string $name) => WorkLabel::create(['name' => $name]));
        $item->labels()->attach($labels->modelKeys());
        $item->comments()->create(['user_id' => auth()->id(), 'body' => 'First']);
        $item->comments()->create(['user_id' => auth()->id(), 'body' => 'Second']);

        Model::preventLazyLoading();

        try {
            $panel = app(WorkItemPanel::class)->present(app(WorkItemPanel::class)->find($item->getKey()), auth()->user());

            $this->board($item)
                ->assertSee('Checkout redesign')
                ->assertSee('Validate the CVC')
                ->assertSee('Payment page')
                ->assertSee('OCI');
        } finally {
            Model::preventLazyLoading(false);
        }

        $this->assertSame($parent->key, $panel['parent']['key']);
        $this->assertCount(2, $panel['subtasks']);
        $this->assertSame(['blocks', 'relates to'], array_column($panel['links'], 'relation'));
        $this->assertSame(['Second', 'First'], array_column($panel['comments'], 'body'));
        $this->assertSame('Sana Malik', $panel['assignee_name']);
        $this->assertTrue($panel['is_mine']);
    }

    /** The status menu is the board's drag by another name: `moveTo()`, stamps and all. */
    public function test_the_status_menu_moves_the_item(): void
    {
        $item = $this->item();
        $doing = WorkStatus::query()->where('name', 'In progress')->firstOrFail();

        $this->board($item)->call('setItemStatus', $doing->getKey());

        $this->assertSame('In progress', $item->fresh()->status->name);
        $this->assertNotNull($item->fresh()->started_at);
    }

    /** A status from some other workflow is not on the menu, and a forged call does nothing. */
    public function test_the_status_menu_ignores_a_status_of_another_workflow(): void
    {
        $item = $this->item();
        $foreign = WorkWorkflow::create(['name' => 'Elsewhere']);
        $stranger = WorkStatus::create(['work_workflow_id' => $foreign->getKey(), 'name' => 'Limbo', 'category' => WorkStatus::IN_PROGRESS, 'sort' => 1]);

        $this->board($item)->call('setItemStatus', $stranger->getKey());

        $this->assertSame('To do', $item->fresh()->status->name);
    }

    public function test_assign_to_me_uses_the_readers_employee_record(): void
    {
        $item = $this->item();

        $this->board($item)->call('assignToMe');

        $this->assertSame((int) $this->me->getKey(), (int) $item->fresh()->assignee_employee_id);
    }

    public function test_a_comment_lands_on_the_items_thread(): void
    {
        $item = $this->item();

        $this->board($item)
            ->set('comment', '  Reproduced on Safari 17.  ')
            ->call('addComment')
            ->assertSet('comment', '')
            ->assertSee('Reproduced on Safari 17.');

        $comment = Comment::query()->where('commentable_id', $item->getKey())->sole();
        $this->assertSame('Reproduced on Safari 17.', $comment->body);
        $this->assertSame($item->getMorphClass(), $comment->commentable_type);
    }

    public function test_add_subtask_makes_a_keyed_subtask_under_the_item(): void
    {
        $item = $this->item();

        $this->board($item)->set('subtask', 'Write the release note')->call('addSubtask')->assertSet('subtask', '');

        $child = WorkItem::query()->where('parent_id', $item->getKey())->sole();
        $this->assertSame(WorkItem::TYPE_SUBTASK, $child->type);
        $this->assertSame('Write the release note', $child->title);
        $this->assertNotNull($child->key);
    }

    public function test_quick_add_creates_the_item_in_the_column_it_was_typed_in(): void
    {
        $this->board()->call('quickAdd', 1, 'Started straight away');

        $this->assertSame('In progress', WorkItem::query()->where('title', 'Started straight away')->sole()->status->name);
    }

    /** The space around the board: its tabs, the person filter and the grouping, all in the URL. */
    public function test_the_board_draws_its_tabs_people_and_grouping(): void
    {
        $this->item(['title' => 'Assigned card', 'assignee_employee_id' => $this->me->getKey()]);
        $this->item(['title' => 'Unowned card']);

        $page = $this->board()
            ->assertSee('fi-wb-tabs', false)
            ->assertSee('Search board');

        $labels = array_column($page->instance()->tabs(), 'label');
        $this->assertContains('Board', $labels);
        $this->assertContains('Backlog', $labels);

        $this->assertSame([(int) $this->me->getKey()], array_column($page->instance()->people(), 'id'));

        $page->call('pickAssignee', $this->me->getKey())
            ->assertSet('assignee', (int) $this->me->getKey())
            ->assertSee('Assigned card')
            ->assertDontSee('Unowned card')
            ->call('pickAssignee', $this->me->getKey())
            ->assertSet('assignee', null);

        $page->set('group', 'priority')->assertSee('fi-board-lane', false);
        $this->assertSame(WorkBoard::SWIMLANE_NONE, WorkBoard::query()->where('project_id', $this->project->getKey())->value('swimlane'), 'Grouping from the toolbar is never saved to the board.');
    }
}
