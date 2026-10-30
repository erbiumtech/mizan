<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Models\WorkWorkflowRule;
use App\Modules\Work\Services\WorkItems;
use Database\Seeders\WorkWorkflowSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The work API — docs/project-management-plan.md §11.
 *
 * A token without the `work` ability gets a 403 on every route; a move that a workflow rule refuses
 * is a 422 with the reason, exactly as the board says it; and a write from the API is the same
 * write the screens make.
 */
class WorkApiTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $company = $this->setCurrentTenant();
        $this->apiCompany = $company;

        foreach (['projects', 'work'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
        $this->seed(WorkWorkflowSeeder::class);

        $this->project = Project::create([
            'code' => 'PRJ-WEB-01', 'name' => 'The website', 'status' => 'active',
            'work_workflow_id' => WorkWorkflow::default()->getKey(),
        ]);
        app(WorkItems::class)->key($this->project);
    }

    private function actingWithWork(string $role = 'Employee'): User
    {
        $user = $this->actingAsApiUser($role, strtolower($role).'-work@test.local');
        Sanctum::actingAs($user, ['api', 'work']);

        return $user;
    }

    private function item(array $attributes = []): WorkItem
    {
        return app(WorkItems::class)->create($this->project, ['title' => 'Export fails on Safari', ...$attributes]);
    }

    /** A token without the `work` ability gets a 403 on every route. */
    public function test_a_token_without_the_work_ability_is_refused_everywhere(): void
    {
        $this->actingAsApiUser('Administrator', 'noability@test.local');
        $item = $this->item();

        $this->getJson('/api/v1/work/projects')->assertStatus(403);
        $this->getJson('/api/v1/work/items')->assertStatus(403);
        $this->getJson('/api/v1/work/items/'.$item->key)->assertStatus(403);
        $this->postJson('/api/v1/work/items', ['project' => 'WEB', 'title' => 'x'])->assertStatus(403);
        $this->postJson('/api/v1/work/items/'.$item->key.'/move', ['status' => 'Done'])->assertStatus(403);
        $this->getJson('/api/v1/work/my')->assertStatus(403);

        $this->assertSame(1, WorkItem::query()->count(), 'nothing was written');
    }

    public function test_projects_and_the_board(): void
    {
        $this->actingWithWork();
        $this->item();

        $this->getJson('/api/v1/work/projects')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.key', 'WEB')
            ->assertJsonPath('data.0.open_items', 1);

        $board = $this->getJson('/api/v1/work/projects/WEB/board')->assertOk()->json();

        $this->assertSame('To do', $board['columns'][0]['label']);
        $this->assertSame('WEB-1', $board['columns'][0]['lanes'][0]['cards'][0]['key']);

        $this->getJson('/api/v1/work/projects/NOPE/board')->assertStatus(404);
    }

    /** A project key and a title are enough. */
    public function test_filing_an_item_takes_a_key_and_a_title(): void
    {
        $this->actingWithWork();

        $this->postJson('/api/v1/work/items', ['project' => 'web', 'title' => 'From a phone', 'type' => 'Bug'])
            ->assertStatus(201)
            ->assertJsonPath('data.key', 'WEB-1')
            ->assertJsonPath('data.type', 'Bug')
            ->assertJsonPath('data.status.category', 'todo');

        $this->postJson('/api/v1/work/items', ['project' => 'WEB'])->assertStatus(422);
        $this->postJson('/api/v1/work/items', ['project' => 'NOPE', 'title' => 'x'])->assertStatus(404);
    }

    public function test_show_update_and_the_list_filters(): void
    {
        $this->actingWithWork();
        $item = $this->item(['description' => 'Steps…']);

        $this->getJson('/api/v1/work/items/'.$item->key)
            ->assertOk()
            ->assertJsonPath('data.description', 'Steps…');

        $this->patchJson('/api/v1/work/items/'.$item->key, ['title' => 'Retitled', 'estimate_points' => 3])
            ->assertOk()
            ->assertJsonPath('data.title', 'Retitled')
            ->assertJsonPath('data.estimate_points', 3);

        $this->getJson('/api/v1/work/items?project=WEB&status_category=todo')->assertOk()->assertJsonPath('pagination.total', 1);
        $this->getJson('/api/v1/work/items?project=WEB&status_category=done')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->getJson('/api/v1/work/items/WEB-999')->assertStatus(404);
    }

    /** The same moveTo(), the same rules, the same refusal — as a 422 with the reason. */
    public function test_a_move_goes_through_the_workflow_and_a_refusal_is_a_422_with_the_reason(): void
    {
        $this->actingWithWork();
        $item = $this->item();

        $this->postJson('/api/v1/work/items/'.$item->key.'/move', ['status' => 'In progress'])
            ->assertOk()
            ->assertJsonPath('data.status.name', 'In progress');

        $this->assertNotNull($item->fresh()->started_at, 'stamped by the engine, not the API');

        // A rule: Done needs a resolution. The API refuses with the rule's own words.
        $done = WorkWorkflow::default()->statuses()->where('name', 'Done')->firstOrFail();
        WorkWorkflowRule::create(['work_workflow_id' => WorkWorkflow::default()->getKey(), 'kind' => WorkWorkflowRule::REQUIRES_RESOLUTION, 'work_status_id' => $done->getKey()]);

        $this->postJson('/api/v1/work/items/'.$item->key.'/move', ['status' => 'Done'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonFragment(['message' => 'Done needs a resolution — say how it ended.']);

        $this->postJson('/api/v1/work/items/'.$item->key.'/move', ['status' => $done->getKey(), 'resolution' => 'Done'])
            ->assertOk()
            ->assertJsonPath('data.status.category', 'done');

        $this->postJson('/api/v1/work/items/'.$item->key.'/move', ['status' => 'Nowhere'])
            ->assertStatus(422)
            ->assertJsonStructure(['statuses']);
    }

    public function test_comments_and_my_work(): void
    {
        $user = $this->actingWithWork();
        $item = $this->item();

        $this->postJson('/api/v1/work/items/'.$item->key.'/comments', ['body' => 'Looking.'])
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Looking.');

        $this->assertSame((int) $user->getKey(), (int) $item->comments()->first()->user_id, 'attributed to the token\'s user');

        $this->getJson('/api/v1/work/my')->assertOk()->assertJsonStructure(['columns']);
    }

    /** Somebody who may read a board and not drag on it gets a 403 on move — §13.1. */
    public function test_moving_needs_the_move_permission(): void
    {
        // An Accountant holds none of Work's grants; given reading alone, they may look and not move.
        $user = $this->actingWithWork('Accountant');
        $user->givePermissionTo('WorkItemView');
        $item = $this->item();

        $this->getJson('/api/v1/work/items/'.$item->key)->assertOk();
        $this->postJson('/api/v1/work/items/'.$item->key.'/move', ['status' => 'In progress'])->assertStatus(403);
    }
}
