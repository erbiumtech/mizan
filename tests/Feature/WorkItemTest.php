<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Models\WorkWorkflowRule;
use App\Modules\Work\Services\WorkItems;
use App\Modules\Work\Support\Rank;
use Database\Seeders\WorkWorkflowSeeder;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The work item, and the one thing allowed to move it — docs/project-management-plan.md §3 and §4.
 *
 * What is asserted here is the part that cannot be seen on a screen: that a key is minted once and
 * never reused, that the status engine stamps what the reports are computed from, and that each of
 * the four workflow rules refuses what it says it refuses.
 */
class WorkItemTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Project $project;

    private WorkWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'work@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['projects', 'work'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->seed(WorkWorkflowSeeder::class);
        $this->workflow = WorkWorkflow::default();

        $this->project = Project::create([
            'code' => 'PRJ-ERP-01',
            'name' => 'ERP rollout',
            'status' => 'active',
        ]);
    }

    private function statusNamed(string $name): WorkStatus
    {
        return WorkStatus::query()->where('name', $name)->firstOrFail();
    }

    private function item(array $attributes = []): WorkItem
    {
        return app(WorkItems::class)->create($this->project, [
            'title' => 'Export fails on Safari',
            ...$attributes,
        ], auth()->user());
    }

    /** The key is derived from the code, and the number counts from one. */
    public function test_a_project_takes_its_key_from_its_code_and_numbers_from_one(): void
    {
        $first = $this->item();
        $second = $this->item(['title' => 'A second thing']);

        $this->assertSame('ERP', $this->project->fresh()->key);
        $this->assertSame('ERP-1', $first->key);
        $this->assertSame('ERP-2', $second->key);
        $this->assertSame(3, (int) $this->project->fresh()->next_item_number);
    }

    /**
     * A deleted item does not hand its number to the next one.
     *
     * `next_item_number` rather than `max(number) + 1`, and this is the reason: ERP-2 is in somebody's
     * commit message within minutes, and a second ERP-2 is a reference that resolves to the wrong
     * thing for as long as the repository exists.
     */
    public function test_a_number_is_never_reused(): void
    {
        $this->item();
        $second = $this->item(['title' => 'Deleted shortly']);
        $second->delete();

        $this->assertSame('ERP-3', $this->item(['title' => 'The next one'])->key);
    }

    public function test_the_key_cannot_change_once_items_exist(): void
    {
        $this->item();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already has work items');

        app(WorkItems::class)->key($this->project->fresh(), 'OTHER');
    }

    /** New items land in the workflow's first to-do status, not wherever the form felt like. */
    public function test_an_item_opens_in_the_first_todo_status(): void
    {
        $this->assertSame('To do', $this->item()->status->name);
    }

    /**
     * The stamps the reports are computed from, written by the transition and nothing else.
     */
    public function test_moving_stamps_when_work_started_and_when_it_was_resolved(): void
    {
        $item = $this->item();

        $this->assertNull($item->started_at);
        $this->assertNull($item->resolved_at);

        $item->moveTo($this->statusNamed('In progress'));
        $this->assertNotNull($item->started_at, 'in progress stamps the start');
        $this->assertNull($item->resolved_at);

        $started = $item->started_at;

        // In review is in progress too, and the start does not move: it is when work began, not
        // when it last changed column.
        $item->moveTo($this->statusNamed('In review'));
        $this->assertSame($started->timestamp, $item->fresh()->started_at->timestamp);

        $item->moveTo($this->statusNamed('Done'), null, 'Done');
        $this->assertNotNull($item->fresh()->resolved_at);

        // Reopened: the resolution goes with it, because "open" and "Won't do" is a contradiction.
        $item->moveTo($this->statusNamed('To do'));
        $this->assertNull($item->fresh()->resolved_at);
        $this->assertNull($item->fresh()->resolution);
        $this->assertSame($started->timestamp, $item->fresh()->started_at->timestamp, 'but it did start, and that stands');
    }

    public function test_a_rule_can_require_a_resolution_before_done_will_take_it(): void
    {
        $done = $this->statusNamed('Done');

        WorkWorkflowRule::create([
            'work_workflow_id' => $this->workflow->getKey(),
            'kind' => WorkWorkflowRule::REQUIRES_RESOLUTION,
            'work_status_id' => $done->getKey(),
        ]);
        $this->project->update(['work_workflow_id' => $this->workflow->getKey()]);

        $item = $this->item();

        try {
            $item->moveTo($done);
            $this->fail('Done should have refused an item with no resolution.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('needs a resolution', $e->getMessage());
        }

        $this->assertSame('To do', $item->fresh()->status->name, 'and it stayed where it was');

        $item->moveTo($done, null, 'Done');
        $this->assertSame('Done', $item->fresh()->status->name);
    }

    public function test_a_rule_can_require_an_assignee(): void
    {
        $progress = $this->statusNamed('In progress');

        WorkWorkflowRule::create([
            'work_workflow_id' => $this->workflow->getKey(),
            'kind' => WorkWorkflowRule::REQUIRES_ASSIGNEE,
            'work_status_id' => $progress->getKey(),
        ]);
        $this->project->update(['work_workflow_id' => $this->workflow->getKey()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs somebody assigned');

        $this->item()->moveTo($progress);
    }

    public function test_a_rule_can_pin_a_status_to_the_statuses_it_may_move_to(): void
    {
        $todo = $this->statusNamed('To do');
        $progress = $this->statusNamed('In progress');
        $done = $this->statusNamed('Done');

        WorkWorkflowRule::create([
            'work_workflow_id' => $this->workflow->getKey(),
            'kind' => WorkWorkflowRule::ONLY_TO,
            'work_status_id' => $todo->getKey(),
            'to_status_ids' => [$progress->getKey()],
        ]);
        $this->project->update(['work_workflow_id' => $this->workflow->getKey()]);

        $item = $this->item();

        try {
            $item->moveTo($done);
            $this->fail('To do should not have reached Done directly.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('may only move to', $e->getMessage());
        }

        $item->moveTo($progress);
        $this->assertSame('In progress', $item->fresh()->status->name);
    }

    /** One level each way, and the API can set parent_id too — so the model is where it is enforced. */
    public function test_a_subtask_cannot_have_children_of_its_own(): void
    {
        $subtask = $this->item(['title' => 'A subtask', 'type' => WorkItem::TYPE_SUBTASK]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot have children');

        $this->item(['title' => 'Under a subtask', 'parent_id' => $subtask->getKey()]);
    }

    public function test_an_epic_cannot_have_a_parent(): void
    {
        $story = $this->item(['title' => 'A story']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sits at the top');

        $this->item(['title' => 'An epic', 'type' => WorkItem::TYPE_EPIC, 'parent_id' => $story->getKey()]);
    }

    /**
     * Who hears about it, from the columns that name people.
     *
     * No notification code is written for this: `RecordAudience` reads what the item declares.
     */
    public function test_the_audience_is_the_reporter_the_assignee_and_the_watchers(): void
    {
        $watcher = $this->makeUser('Employee', 'watcher@test.local');
        $item = $this->item();
        $item->watch($watcher);

        $audience = collect($item->fresh()->changeAudience());

        $this->assertTrue($audience->contains((int) auth()->id()), 'the reporter asked for it');
        $this->assertTrue($audience->contains((int) $watcher->getKey()), 'the watcher said they cared');
    }

    /** Dragging writes one row, which is the whole reason the column is a string. */
    public function test_ranks_order_and_leave_room_between_any_two(): void
    {
        $a = Rank::between(null, null);
        $b = Rank::last($a);
        $middle = Rank::between($a, $b);

        $this->assertTrue($a < $middle && $middle < $b, "expected {$a} < {$middle} < {$b}");
        $this->assertTrue(Rank::between(null, $a) < $a, 'and there is always room at the top');
    }

    public function test_open_excludes_what_is_done_whatever_the_status_is_called(): void
    {
        $open = $this->item();
        $finished = $this->item(['title' => 'Finished']);
        $finished->moveTo($this->statusNamed('Done'), null, 'Done');

        // A company's own word for done, in the done category — the filter has never seen it.
        $shipped = WorkStatus::create([
            'work_workflow_id' => $this->workflow->getKey(),
            'name' => 'Shipped',
            'category' => WorkStatus::DONE,
            'sort' => 50,
        ]);
        $alsoFinished = $this->item(['title' => 'Also finished']);
        $alsoFinished->moveTo($shipped);

        $keys = WorkItem::query()->open()->pluck('key')->all();

        $this->assertSame([$open->key], $keys, 'the category decides, not the name');
    }
}
