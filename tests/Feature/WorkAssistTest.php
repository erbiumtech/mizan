<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Filament\Pages\Timeline;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkRelease;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Services\WorkAssists;
use App\Modules\Work\Services\WorkItems;
use App\Support\Ai\FakeStructuredModel;
use App\Support\Ai\ModelUnavailable;
use App\Support\Ai\StructuredModel;
use App\Support\TenantSettings;
use Database\Seeders\WorkWorkflowSeeder;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The timeline and the three assists — docs/project-management-plan.md §6.5 and §12.
 *
 * The model is faked. What is asserted about the assists is the plan's ceiling: nothing is on
 * the write path without a person clicking — a draft saves nothing, a summary is stored only when
 * asked for and marked as generated, an estimate is never written — and a model that is off or
 * did not answer is no assist rather than an error.
 */
class WorkAssistTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Project $project;

    private FakeStructuredModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'assist@test.local'));
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
            'code' => 'PRJ-WEB-01', 'name' => 'The website', 'status' => 'active',
            'work_workflow_id' => WorkWorkflow::default()->getKey(),
        ]);

        $this->model = new FakeStructuredModel;
        $this->app->instance(StructuredModel::class, $this->model);
    }

    private function item(array $attributes = []): WorkItem
    {
        return app(WorkItems::class)->create($this->project, ['title' => 'Export fails on Safari', ...$attributes], auth()->user());
    }

    private function done(WorkItem $item): WorkItem
    {
        return $item->moveTo(WorkStatus::query()->where('name', 'Done')->firstOrFail(), null, 'Done');
    }

    /** A draft is form state. Nothing is written. */
    public function test_a_sentence_becomes_a_draft_and_saves_nothing(): void
    {
        $this->model->queue([
            'title' => 'Export fails on Safari for the March payroll',
            'description' => "## Steps\n1. …\n## Expected\n…\n## Actual\n…",
            'type' => 'Bug',
            'priority' => 'High',
            'labels' => ['Export', 'safari', 'a third that is dropped'],
        ]);

        $draft = app(WorkAssists::class)->draft("Users can't export the March payroll as PDF on Safari", $this->project);

        $this->assertSame('Bug', $draft['type']);
        $this->assertSame('High', $draft['priority']);
        $this->assertSame(['export', 'safari'], $draft['labels'], 'two, lowercased');
        $this->assertStringContainsString('## Steps', $draft['description']);
        $this->assertSame(0, WorkItem::query()->count(), 'a draft is not an item');

        $this->assertStringContainsString('Allowed types: Epic, Story, Task, Bug, Subtask', $this->model->calls[0]['system']);
    }

    public function test_a_model_that_does_not_answer_is_no_draft_not_an_error(): void
    {
        $this->model->queue(new ModelUnavailable('off'));

        $this->assertNull(app(WorkAssists::class)->draft('anything', $this->project));
    }

    /** Offered past ten comments; stored and stamped only when asked for. */
    public function test_a_long_thread_is_summarised_on_request_and_marked_as_generated(): void
    {
        $item = $this->item();

        $this->assertFalse(app(WorkAssists::class)->canSummarise($item), 'no thread yet');

        foreach (range(1, 11) as $i) {
            $item->comments()->create(['user_id' => auth()->id(), 'body' => 'Comment '.$i]);
        }

        $this->assertTrue(app(WorkAssists::class)->canSummarise($item));
        $this->assertNull($item->fresh()->ai_summary, 'nothing until somebody asks');

        $this->model->queue(['summary' => "Decided: fix the pager.\nOpen: Safari 17.\nAhmed is waiting on Sara."]);

        $summary = app(WorkAssists::class)->summarise($item);

        $this->assertStringStartsWith('Decided: fix the pager.', $summary);
        $this->assertSame($summary, $item->fresh()->ai_summary);
        $this->assertNotNull($item->fresh()->ai_summary_at, 'stamped, so the page can say when');
        $this->assertStringContainsString('Comment 11', $this->model->calls[0]['utterance'], 'the whole thread went in');
    }

    /** From three similar finished items, never written. */
    public function test_an_estimate_is_drawn_from_similar_finished_items_and_never_written(): void
    {
        app(TenantSettings::class)->set('work.estimation_unit', 'hours');

        $this->done($this->item(['title' => 'Export fails on Firefox', 'estimate_minutes' => 120]));
        $this->done($this->item(['title' => 'Export fails on Chrome', 'estimate_minutes' => 90]));
        $this->done($this->item(['title' => 'PDF export is slow', 'estimate_minutes' => 240]));
        $this->done($this->item(['title' => 'Login page typo', 'estimate_minutes' => 15]));
        $this->item(['title' => 'Export fails on Edge', 'estimate_minutes' => 60]); // not done: not a neighbour

        $item = $this->item(['title' => 'Export fails on Safari']);

        $this->model->queue(['estimate' => 110, 'reason' => 'The two browser export bugs took 90 and 120 minutes.']);

        $suggestion = app(WorkAssists::class)->estimate($item);

        $this->assertSame('hours', $suggestion['unit']);
        $this->assertSame(110, $suggestion['estimate']);
        $this->assertCount(3, $suggestion['drawn_from']);
        $this->assertSame(['WEB-1', 'WEB-2', 'WEB-3'], array_column($suggestion['drawn_from'], 'key'), 'the three that share the most words, finished and estimated');
        $this->assertNull($item->fresh()->estimate_minutes, 'suggested, not written');
    }

    public function test_an_item_with_no_similar_finished_neighbour_gets_no_estimate_and_no_call(): void
    {
        $item = $this->item(['title' => 'Something entirely new']);

        $this->assertNull(app(WorkAssists::class)->estimate($item));
        $this->assertSame([], $this->model->calls, 'nothing to draw on, so the model is not asked');
    }

    /** The timeline draws what is dated and nothing else, read-only. */
    public function test_the_timeline_draws_dated_epics_releases_and_sprints(): void
    {
        $epic = $this->item(['title' => 'Checkout rewrite', 'type' => 'Epic']);
        $this->item(['title' => 'One', 'parent_id' => $epic->getKey(), 'due_on' => '2026-11-20']);
        $two = $this->item(['title' => 'Two', 'parent_id' => $epic->getKey(), 'due_on' => '2026-12-05']);
        $this->done($two);
        $this->item(['title' => 'Undated epic', 'type' => 'Epic']);

        WorkRelease::create(['project_id' => $this->project->getKey(), 'name' => '2.4.0', 'planned_on' => '2026-12-10']);

        $page = Livewire::test(Timeline::class, ['project' => $this->project->getKey()])->assertSuccessful();
        $timeline = $page->instance()->timeline();

        $this->assertCount(1, $timeline['epics'], 'the undated epic has nothing to be drawn between');
        $this->assertSame(50, $timeline['epics'][0]['percent']);
        $this->assertCount(2, $timeline['epics'][0]['ticks']);
        $this->assertTrue($timeline['epics'][0]['ticks'][1]['done']);
        $this->assertSame('2.4.0', $timeline['releases'][0]['name']);
        $this->assertSame('2026-12-31', $timeline['to'], 'whole months');

        $page->assertSee('Checkout rewrite')->assertSee('2.4.0')->assertDontSee('Undated epic');
    }
}
