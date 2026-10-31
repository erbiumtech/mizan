<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Projects\Filament\Resources\Projects\Pages\EditProject;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Filament\Pages\Board;
use App\Modules\Work\Filament\Resources\WorkWorkflows\WorkWorkflowResource;
use App\Modules\Work\Models\WorkBoard;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Services\BoardSettings;
use App\Modules\Work\Services\WorkItems;
use Database\Seeders\WorkWorkflowSeeder;
use Filament\Forms\Components\Repeater;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Board settings, more than one board, and the project's choice of workflow.
 *
 * What is asserted is what a save keeps: the columns as given, each limit with its column through a
 * reorder, the lanes; the refusals that stop a card going missing; a second board reachable by the
 * address and deletable while it is not the last; and a workflow that changes only when no item
 * would be stranded by it, redrawing the boards when it does.
 */
class WorkBoardSettingsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Project $project;

    private WorkWorkflow $workflow;

    private WorkBoard $board;

    private ?\Closure $undoRepeaterFake = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'settings@test.local'));
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
            'code' => 'PRJ-SET-01',
            'name' => 'Settings project',
            'status' => 'active',
            'work_workflow_id' => $this->workflow->getKey(),
        ]);
        $this->board = WorkBoard::defaultFor($this->project, $this->workflow);

        // Repeater items keyed 0, 1, 2 rather than by uuid, so the form's state can be written here.
        $this->undoRepeaterFake = Repeater::fake();
    }

    protected function tearDown(): void
    {
        if ($this->undoRepeaterFake !== null) {
            ($this->undoRepeaterFake)();
        }

        parent::tearDown();
    }

    private function statusId(string $name): int
    {
        return (int) WorkStatus::query()->where('work_workflow_id', $this->workflow->getKey())->where('name', $name)->value('id');
    }

    private function item(array $attributes = []): WorkItem
    {
        return app(WorkItems::class)->create($this->project, ['title' => 'A thing', ...$attributes], auth()->user());
    }

    /** A second workflow with statuses of its own. */
    private function otherWorkflow(): WorkWorkflow
    {
        $workflow = WorkWorkflow::create(['name' => 'Client approvals']);

        foreach ([['Drafted', WorkStatus::TODO], ['With the client', WorkStatus::IN_PROGRESS], ['Signed off', WorkStatus::DONE]] as $sort => [$name, $category]) {
            WorkStatus::create(['work_workflow_id' => $workflow->getKey(), 'name' => $name, 'category' => $category, 'sort' => $sort]);
        }

        return $workflow->load('statuses');
    }

    public function test_settings_save_the_columns_their_limits_and_the_lanes(): void
    {
        Livewire::test(Board::class, ['project' => $this->project->getKey()])
            ->callAction('boardSettings', data: [
                'name' => 'Delivery',
                'kind' => WorkBoard::KANBAN,
                'swimlane' => 'assignee',
                'shows_subtasks' => true,
                'columns' => [
                    ['label' => 'Ready', 'status_ids' => [$this->statusId('To do')], 'limit' => null],
                    ['label' => 'Doing', 'status_ids' => [$this->statusId('In progress'), $this->statusId('In review')], 'limit' => 3],
                    ['label' => 'Finished', 'status_ids' => [$this->statusId('Done')], 'limit' => null],
                ],
            ])
            ->assertHasNoActionErrors();

        $board = $this->board->fresh();

        $this->assertSame('Delivery', $board->name);
        $this->assertSame('assignee', $board->swimlane);
        $this->assertTrue($board->shows_subtasks);
        $this->assertSame(['Ready', 'Doing', 'Finished'], array_column($board->columns, 'label'));
        $this->assertSame([$this->statusId('In progress'), $this->statusId('In review')], $board->columns[1]['status_ids']);
        $this->assertSame(3, $board->limitFor(1));
        $this->assertNull($board->limitFor(0));
    }

    /** A limit belongs to its column: move the column and the limit goes with it. */
    public function test_a_limit_moves_with_its_column(): void
    {
        $settings = app(BoardSettings::class);
        $state = $settings->formState($this->board);
        $state['columns'][1]['limit'] = 4;

        $settings->save($this->board, $state, $this->workflow);
        $this->assertSame(4, $this->board->fresh()->limitFor(1));

        // In progress dragged to the front.
        $state = $settings->formState($this->board->fresh());
        $state['columns'] = [$state['columns'][1], $state['columns'][0], $state['columns'][2], $state['columns'][3]];
        $settings->save($this->board->fresh(), $state, $this->workflow);

        $board = $this->board->fresh();
        $this->assertSame('In progress', $board->columns[0]['label']);
        $this->assertSame(4, $board->limitFor(0));
        $this->assertNull($board->limitFor(1));
    }

    public function test_a_status_may_be_in_one_column_only(): void
    {
        $todo = $this->statusId('To do');

        Livewire::test(Board::class, ['project' => $this->project->getKey()])
            ->callAction('boardSettings', data: [
                'name' => 'Board',
                'kind' => WorkBoard::KANBAN,
                'swimlane' => 'none',
                'shows_subtasks' => false,
                'columns' => [
                    ['label' => 'First', 'status_ids' => [$todo], 'limit' => null],
                    ['label' => 'Second', 'status_ids' => [$todo, $this->statusId('Done')], 'limit' => null],
                ],
            ])
            ->assertHasActionErrors(['columns']);

        $this->assertSame(['To do', 'In progress', 'In review', 'Done'], array_column($this->board->fresh()->columns, 'label'), 'nothing was saved');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('in two columns');

        app(BoardSettings::class)->save($this->board, [
            'name' => 'Board',
            'swimlane' => 'none',
            'columns' => [
                ['label' => 'First', 'status_ids' => [$todo]],
                ['label' => 'Second', 'status_ids' => [$todo]],
            ],
        ], $this->workflow);
    }

    public function test_a_column_needs_a_status_and_a_limit_is_a_whole_number(): void
    {
        $settings = app(BoardSettings::class);

        $this->assertStringContainsString('shows no status', (string) $settings->columnProblem([['label' => 'Empty', 'status_ids' => []]], $this->workflow));
        $this->assertStringContainsString('whole number', (string) $settings->columnProblem([['label' => 'Doing', 'status_ids' => [$this->statusId('To do')], 'limit' => 0]], $this->workflow));
        $this->assertStringContainsString('at least one column', (string) $settings->columnProblem([], $this->workflow));
        $this->assertStringContainsString('not in this project', (string) $settings->columnProblem([['label' => 'Other', 'status_ids' => [999999]]], $this->workflow));
        $this->assertNull($settings->columnProblem([['label' => 'Only to do', 'status_ids' => [$this->statusId('To do')], 'limit' => '2']], $this->workflow), 'a status in no column is allowed');
    }

    public function test_reset_goes_back_to_one_column_per_status_with_no_limits(): void
    {
        $this->board->update([
            'columns' => [['label' => 'Everything', 'status_ids' => [$this->statusId('To do'), $this->statusId('Done')]]],
            'wip_limits' => ['0' => 2],
        ]);

        Livewire::test(Board::class, ['project' => $this->project->getKey()])
            ->callAction('resetColumns')
            ->assertNotified();

        $board = $this->board->fresh();
        $this->assertSame(['To do', 'In progress', 'In review', 'Done'], array_column($board->columns, 'label'));
        $this->assertSame([], $board->wip_limits);
    }

    public function test_a_new_board_is_chosen_by_the_address_and_the_first_stays_the_default(): void
    {
        $page = Livewire::test(Board::class, ['project' => $this->project->getKey()])
            ->callAction('newBoard', data: ['name' => 'Support desk', 'kind' => WorkBoard::SCRUM])
            ->assertHasNoActionErrors();

        $support = WorkBoard::query()->where('name', 'Support desk')->firstOrFail();

        $page->assertSet('boardId', $support->getKey());
        $this->assertSame(WorkBoard::SCRUM, $support->kind);
        $this->assertSame(['To do', 'In progress', 'In review', 'Done'], array_column($support->columns, 'label'));

        Livewire::test(Board::class, ['project' => $this->project->getKey(), 'boardId' => $support->getKey()])
            ->assertSuccessful()
            ->assertSee('Support desk');

        // Nothing chosen is the project's first board.
        $this->assertSame($this->board->getKey(), WorkBoard::defaultFor($this->project, $this->workflow)->getKey());

        // A board of another project is not reachable by putting its id in this project's address.
        $other = Project::create(['code' => 'PRJ-SET-02', 'name' => 'Elsewhere', 'status' => 'active']);
        $foreign = app(BoardSettings::class)->create($other, 'Not yours', WorkBoard::KANBAN, $this->workflow);

        Livewire::test(Board::class, ['project' => $this->project->getKey(), 'boardId' => $foreign->getKey()])
            ->assertSuccessful()
            ->assertDontSee('Not yours');
    }

    public function test_a_board_can_be_deleted_but_never_the_last(): void
    {
        Livewire::test(Board::class, ['project' => $this->project->getKey()])
            ->assertActionHidden('deleteBoard');

        $second = app(BoardSettings::class)->create($this->project, 'Second', WorkBoard::KANBAN, $this->workflow);

        Livewire::test(Board::class, ['project' => $this->project->getKey(), 'boardId' => $second->getKey()])
            ->assertActionVisible('deleteBoard')
            ->callAction('deleteBoard')
            ->assertSet('boardId', null);

        $this->assertNull(WorkBoard::query()->find($second->getKey()));
        $this->assertNotNull(WorkBoard::query()->find($this->board->getKey()));

        $this->expectException(InvalidArgumentException::class);
        app(BoardSettings::class)->delete($this->board);
    }

    public function test_somebody_without_board_manage_sees_no_settings(): void
    {
        $this->actingAs($this->makeUser('Employee', 'reader@test.local'));

        Livewire::test(Board::class, ['project' => $this->project->getKey()])
            ->assertSuccessful()
            ->assertActionHidden('boardSettings')
            ->assertActionHidden('newBoard')
            ->assertActionHidden('resetColumns')
            ->assertActionHidden('deleteBoard')
            ->assertActionHidden('editWorkflow');
    }

    /** The statuses a column may hold are the workflow's, so the board offers a way to that workflow. */
    public function test_the_board_offers_the_projects_workflow_to_edit(): void
    {
        Livewire::test(Board::class, ['project' => $this->project->getKey()])
            ->assertActionVisible('editWorkflow')
            ->assertActionHasUrl('editWorkflow', WorkWorkflowResource::getUrl('edit', ['record' => $this->workflow]));
    }

    public function test_the_workflow_field_is_offered_only_where_the_company_has_work(): void
    {
        Livewire::test(EditProject::class, ['record' => $this->project->getKey()])
            ->assertFormFieldVisible('work_workflow_id');

        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->where('module', 'work')
            ->update(['enabled' => false]);
        modules()->flush();

        Livewire::test(EditProject::class, ['record' => $this->project->getKey()])
            ->assertFormFieldHidden('work_workflow_id');
    }

    public function test_a_workflow_change_that_would_strand_items_is_refused(): void
    {
        $this->item(['title' => 'Already under way']);
        $other = $this->otherWorkflow();

        Livewire::test(EditProject::class, ['record' => $this->project->getKey()])
            ->fillForm(['work_workflow_id' => $other->getKey()])
            ->call('save')
            ->assertHasFormErrors(['work_workflow_id']);

        $this->assertSame($this->workflow->getKey(), (int) $this->project->fresh()->work_workflow_id);

        // The same change made any other way is refused by the model guard.
        try {
            $this->project->fresh()->update(['work_workflow_id' => $other->getKey()]);
            $this->fail('A change that strands an item was saved.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('work_workflow_id', $e->errors());
        }

        $this->assertSame($this->workflow->getKey(), (int) $this->project->fresh()->work_workflow_id);
    }

    public function test_a_workflow_change_with_nothing_stranded_redraws_the_boards(): void
    {
        $second = app(BoardSettings::class)->create($this->project, 'Second', WorkBoard::KANBAN, $this->workflow);
        $this->board->update(['wip_limits' => ['1' => 2]]);
        $other = $this->otherWorkflow();

        Livewire::test(EditProject::class, ['record' => $this->project->getKey()])
            ->fillForm(['work_workflow_id' => $other->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($other->getKey(), (int) $this->project->fresh()->work_workflow_id);

        foreach ([$this->board, $second] as $board) {
            $board = $board->fresh();
            $this->assertSame(['Drafted', 'With the client', 'Signed off'], array_column($board->columns, 'label'));
            $this->assertSame([], $board->wip_limits);
        }

        // Naming the default a project already used by default keeps the boards as they are.
        $plain = Project::create(['code' => 'PRJ-SET-03', 'name' => 'Plain', 'status' => 'active']);
        $plainBoard = WorkBoard::defaultFor($plain, $this->workflow);
        $plainBoard->update(['wip_limits' => ['0' => 5]]);
        $plain->update(['work_workflow_id' => $this->workflow->getKey()]);

        $this->assertSame(5, $plainBoard->fresh()->limitFor(0));
    }
}
