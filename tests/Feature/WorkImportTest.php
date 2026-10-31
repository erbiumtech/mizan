<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Services\CsvImportService;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Imports\TrelloImport;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkWorkflow;
use App\Support\CsvImporters;
use Database\Seeders\WorkWorkflowSeeder;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Imports — docs/project-management-plan.md §8.9.
 *
 * A team arriving from Jira or Trello with four hundred open items will not retype them. What is
 * worth asserting: the source key survives in `imported_from` so the old reference still finds the
 * item, statuses land on the workflow by name or by what the name means, and running the same file
 * twice corrects rather than duplicates.
 */
class WorkImportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'import@test.local'));
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
            'code' => 'PRJ-WEB-01', 'name' => 'The website', 'status' => 'active', 'key' => 'WEB',
            'work_workflow_id' => WorkWorkflow::default()->getKey(),
        ]);
    }

    public function test_jira_is_a_registered_import_type_whose_template_passes_its_own_preview(): void
    {
        $this->assertTrue(CsvImporters::has('jira'));

        $imports = app(CsvImportService::class);
        $preview = $imports->preview($imports->template('jira'), 'jira');

        $this->assertSame(1, $preview['ready']);
        $this->assertSame(0, $preview['skipped']);
    }

    public function test_a_jira_export_lands_on_the_project_with_its_key_and_statuses_mapped(): void
    {
        $csv = implode("\n", [
            'Issue key,Summary,Issue Type,Status,Priority,Assignee,Reporter,Description,Created,Resolved,Parent,Story Points',
            'WEB-40,Checkout rewrite,Epic,In Progress,High,,,Big one,2026-01-10 09:00,,,',
            'WEB-41,Export fails on Safari,Bug,Done,High,,,Steps,2026-01-11 09:00,2026-01-15 17:00,WEB-40,3',
            'WEB-42,Pager is off by one,Task,Selected for Development,Medium,,,,2026-01-12 09:00,,,',
            'OTHER-1,Belongs elsewhere,Task,To Do,Low,,,,2026-01-12 09:00,,,',
            'notakey,Bad row,Task,To Do,Low,,,,,,,',
        ]);

        $result = app(CsvImportService::class)->import($csv, 'jira');

        $this->assertSame(3, $result['imported'], 'three of the project; OTHER-1 has no project here; the bad key is named');
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('not a Jira issue key', $result['skipped'][0]);

        $epic = WorkItem::query()->where('imported_from', 'jira:WEB-40')->firstOrFail();
        $bug = WorkItem::query()->where('imported_from', 'jira:WEB-41')->firstOrFail();
        $task = WorkItem::query()->where('imported_from', 'jira:WEB-42')->firstOrFail();

        $this->assertSame('Epic', $epic->type);
        $this->assertSame('In progress', $epic->status->name, 'mapped by name, case aside');
        $this->assertSame('Done', $bug->status->name);
        $this->assertSame('2026-01-15', $bug->resolved_at->toDateString(), 'Jira\'s own dates, so the reports read history');
        $this->assertSame((int) $epic->getKey(), (int) $bug->parent_id, 'the parent came along');
        $this->assertSame(3.0, (float) $bug->estimate_points);
        $this->assertSame('In progress', $task->status->name, '"Selected for Development" means in progress');

        // The old reference still finds it.
        $this->assertNotNull(WorkItem::query()->where('imported_from', 'like', '%WEB-41')->first());
        $this->assertStringContainsString('Imported from Jira as WEB-41', $bug->description);

        // Twice corrects rather than duplicates.
        app(CsvImportService::class)->import(str_replace('Export fails on Safari', 'Export fails on Safari 17', $csv), 'jira');
        $this->assertSame(3, WorkItem::query()->where('project_id', $this->project->getKey())->count());
        $this->assertSame('Export fails on Safari 17', $bug->fresh()->title);
    }

    public function test_a_trello_board_becomes_items_with_lists_as_statuses_and_labels_kept(): void
    {
        $board = [
            'lists' => [
                ['id' => 'l1', 'name' => 'Ideas'],
                ['id' => 'l2', 'name' => 'Doing'],
                ['id' => 'l3', 'name' => 'Done'],
            ],
            'labels' => [['id' => 'lab1', 'name' => 'frontend', 'color' => 'green']],
            'members' => [['id' => 'm1', 'fullName' => 'Ahmed Khan']],
            'cards' => [
                ['id' => 'c1', 'name' => 'Dark mode', 'desc' => 'Someday', 'idList' => 'l1', 'pos' => 2, 'idLabels' => ['lab1'], 'idMembers' => ['m1'], 'shortUrl' => 'https://trello.com/c/c1'],
                ['id' => 'c2', 'name' => 'Pager', 'desc' => '', 'idList' => 'l2', 'pos' => 1, 'due' => '2026-12-01T10:00:00.000Z'],
                ['id' => 'c3', 'name' => 'Login', 'desc' => '', 'idList' => 'l3', 'pos' => 1, 'closed' => true],
                ['id' => 'c4', 'name' => '', 'idList' => 'l1'],
            ],
        ];

        $result = app(TrelloImport::class)->run($this->project, $board);

        $this->assertSame(3, $result['imported']);
        $this->assertSame(1, $result['skipped']);

        $dark = WorkItem::query()->where('imported_from', 'trello:c1')->firstOrFail();
        $pager = WorkItem::query()->where('imported_from', 'trello:c2')->firstOrFail();
        $login = WorkItem::query()->where('imported_from', 'trello:c3')->firstOrFail();

        $this->assertSame('To do', $dark->status->name, '"Ideas" is nothing the workflow knows, so it is to do');
        $this->assertSame('In progress', $pager->status->name, '"Doing" means in progress');
        $this->assertSame('Done', $login->status->name);
        $this->assertNotNull($login->archived_at, 'a closed card is archived, not deleted');
        $this->assertSame('2026-12-01', $pager->due_on->toDateString());
        $this->assertSame(['frontend'], $dark->labels->pluck('name')->all());
        $this->assertStringContainsString('Trello members: Ahmed Khan', $dark->description);
        $this->assertStringContainsString('Lists mapped by what their name suggests', implode(' ', $result['notes']));

        // Twice corrects rather than duplicates.
        $board['cards'][0]['name'] = 'Dark mode, finally';
        app(TrelloImport::class)->run($this->project, $board);
        $this->assertSame(3, WorkItem::query()->where('project_id', $this->project->getKey())->count());
        $this->assertSame('Dark mode, finally', $dark->fresh()->title);
    }
}
