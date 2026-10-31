<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Filament\Pages\Backlog;
use App\Modules\Work\Filament\Pages\Epics;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkRelease;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Services\Releases;
use App\Modules\Work\Services\WorkItems;
use Database\Seeders\WorkWorkflowSeeder;
use Livewire\Livewire;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Epics and releases — docs/project-management-plan.md §6.3 and §6.4.
 *
 * Two things are worth asserting about a release: that shipping it refuses to go quietly with open
 * work inside, and that the notes list exactly what was finished. And one about an Epic: that its
 * progress is read from its children's categories and nowhere else.
 */
class WorkReleaseTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'release@test.local'));
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

    private function release(array $attributes = []): WorkRelease
    {
        return WorkRelease::create([
            'project_id' => $this->project->getKey(),
            'name' => '2.4.0',
            'planned_on' => now()->addDays(7)->toDateString(),
            ...$attributes,
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

    /** Releasing stamps the date and leaves every item exactly where it was. */
    public function test_releasing_stamps_the_date_and_moves_nothing(): void
    {
        $release = $this->release();
        $item = $this->done($this->item(['release_id' => $release->getKey()]));

        app(Releases::class)->release($release);

        $this->assertTrue($release->fresh()->isReleased());
        $this->assertSame(today()->toDateString(), $release->fresh()->released_on->toDateString());
        $this->assertSame((int) $release->getKey(), (int) $item->fresh()->release_id, 'the item stayed in the release');
        $this->assertSame('Done', $item->fresh()->status->name, 'and its status was not touched');
    }

    /** With open work inside, releasing has to be told what happens to it. */
    public function test_a_release_with_open_items_asks_before_it_goes_out(): void
    {
        $release = $this->release();
        $this->item(['title' => 'Still open', 'release_id' => $release->getKey()]);

        try {
            app(Releases::class)->release($release);
            $this->fail('a release with open work should not have gone out silently');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('still open', $e->getMessage());
        }

        $this->assertFalse($release->fresh()->isReleased());
    }

    public function test_open_items_can_be_moved_to_the_next_release_on_the_way_out(): void
    {
        $release = $this->release();
        $next = $this->release(['name' => '2.5.0', 'planned_on' => now()->addDays(21)->toDateString()]);

        $finished = $this->done($this->item(['title' => 'Finished', 'release_id' => $release->getKey()]));
        $open = $this->item(['title' => 'Not finished', 'release_id' => $release->getKey()]);

        app(Releases::class)->release($release, $next);

        $this->assertTrue($release->fresh()->isReleased());
        $this->assertSame((int) $release->getKey(), (int) $finished->fresh()->release_id, 'what shipped stays attributed to the version it shipped in');
        $this->assertSame((int) $next->getKey(), (int) $open->fresh()->release_id, 'what did not ship is promised to the next one');
    }

    /** Shipping open, behind a flag, is a choice — and only when said out loud. */
    public function test_open_items_may_ship_open_when_told_so(): void
    {
        $release = $this->release();
        $open = $this->item(['title' => 'Behind a flag', 'release_id' => $release->getKey()]);

        app(Releases::class)->release($release, keepOpen: true);

        $this->assertTrue($release->fresh()->isReleased());
        $this->assertSame((int) $release->getKey(), (int) $open->fresh()->release_id);
    }

    /** The form's "out of any release": the open items go back to the plain backlog, and the release ships. */
    public function test_open_items_can_be_taken_out_of_any_release_on_the_way_out(): void
    {
        $release = $this->release();
        $open = $this->item(['title' => 'Not finished', 'release_id' => $release->getKey()]);

        app(Releases::class)->release($release, null, keepOpen: false, clearOpen: true);

        $this->assertTrue($release->fresh()->isReleased());
        $this->assertNull($open->fresh()->release_id);
    }

    public function test_open_work_cannot_be_moved_into_a_released_release(): void
    {
        $release = $this->release();
        $shipped = $this->release(['name' => '2.3.0', 'released_on' => now()->subDay()->toDateString()]);
        $this->item(['release_id' => $release->getKey()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already gone out');

        app(Releases::class)->release($release, $shipped);
    }

    public function test_a_released_release_cannot_be_released_again(): void
    {
        $release = $this->release(['released_on' => now()->subDay()->toDateString()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already');

        app(Releases::class)->release($release);
    }

    /**
     * The notes are what was finished, one line each, grouped by type — and nothing that is still open.
     */
    public function test_the_release_notes_list_finished_items_grouped_by_type(): void
    {
        $release = $this->release();

        $this->done($this->item(['title' => 'Export fails on Safari', 'type' => 'Bug', 'release_id' => $release->getKey()]));
        $this->done($this->item(['title' => 'Invoice run is unattended', 'type' => 'Story', 'release_id' => $release->getKey()]));
        $this->item(['title' => 'Not finished yet', 'type' => 'Bug', 'release_id' => $release->getKey()]);
        $this->done($this->item(['title' => 'In another release', 'type' => 'Bug']));

        $notes = app(Releases::class)->notes($release);

        $this->assertStringStartsWith('# 2.4.0', $notes);
        $this->assertStringContainsString("## Story\n\n- WEB-2 Invoice run is unattended", $notes);
        $this->assertStringContainsString("## Bug\n\n- WEB-1 Export fails on Safari", $notes);
        $this->assertStringNotContainsString('Not finished yet', $notes, 'open work is not in the notes');
        $this->assertStringNotContainsString('In another release', $notes);

        // In the option list's order: Story comes before Bug, whatever the alphabet says.
        $this->assertLessThan(strpos($notes, '## Bug'), strpos($notes, '## Story'));
    }

    /** An Epic's progress is its children's categories, by count, and goes back down when one reopens. */
    public function test_an_epics_progress_is_read_from_its_children(): void
    {
        $epic = $this->item(['title' => 'Checkout rewrite', 'type' => 'Epic']);
        $one = $this->item(['title' => 'One', 'parent_id' => $epic->getKey(), 'estimate_points' => 3]);
        $two = $this->item(['title' => 'Two', 'parent_id' => $epic->getKey(), 'estimate_points' => 5]);
        $this->item(['title' => 'Three', 'parent_id' => $epic->getKey()]);

        $this->assertSame(['total' => 3, 'done' => 0, 'points' => 8.0, 'points_done' => 0.0, 'percent' => 0], $epic->progress());

        $this->done($one);
        $this->done($two);

        $progress = $epic->fresh()->progress();
        $this->assertSame(2, $progress['done']);
        $this->assertSame(8.0, $progress['points_done']);
        $this->assertSame(67, $progress['percent'], 'by count, not by points');

        // And an Epic is not done because its children are: that is the company's call.
        $this->assertFalse($epic->fresh()->isDone());

        $two->fresh()->moveTo(WorkStatus::query()->where('name', 'To do')->firstOrFail());
        $this->assertSame(33, $epic->fresh()->progress()['percent'], 'a reopened child takes the bar back down');
    }

    public function test_the_epics_page_lists_open_epics_with_their_children(): void
    {
        $epic = $this->item(['title' => 'Checkout rewrite', 'type' => 'Epic']);
        $this->item(['title' => 'A story under it', 'parent_id' => $epic->getKey()]);
        $this->done($this->item(['title' => 'A finished epic', 'type' => 'Epic']));

        Livewire::test(Epics::class, ['project' => $this->project->getKey()])
            ->assertSuccessful()
            ->assertSee('Checkout rewrite')
            ->assertSee('A story under it')
            ->assertDontSee('A finished epic')
            ->set('done', true)
            ->assertSee('A finished epic');
    }

    /** The backlog's other two bulk changes — §6.1. */
    public function test_the_backlog_promises_a_handful_to_a_release_and_puts_them_under_an_epic(): void
    {
        $release = $this->release();
        $epic = $this->item(['title' => 'Checkout rewrite', 'type' => 'Epic']);
        $one = $this->item(['title' => 'One']);
        $two = $this->item(['title' => 'Two']);
        $other = $this->item(['title' => 'Untouched']);

        Livewire::test(Backlog::class, ['project' => $this->project->getKey()])
            ->set('selected', [$one->getKey(), $two->getKey()])
            ->call('setRelease', $release->getKey())
            ->assertSet('selected', [])
            ->set('selected', [$one->getKey(), $two->getKey(), $epic->getKey()])
            ->call('setEpic', $epic->getKey())
            ->assertSet('selected', []);

        $this->assertSame((int) $release->getKey(), (int) $one->fresh()->release_id);
        $this->assertSame((int) $release->getKey(), (int) $two->fresh()->release_id);
        $this->assertNull($other->fresh()->release_id);

        $this->assertSame((int) $epic->getKey(), (int) $one->fresh()->parent_id);
        $this->assertSame((int) $epic->getKey(), (int) $two->fresh()->parent_id);
        $this->assertNull($epic->fresh()->parent_id, 'an epic ticked by mistake is skipped, not put under itself');
    }

    public function test_the_backlog_will_not_promise_work_into_a_released_release(): void
    {
        $shipped = $this->release(['released_on' => now()->subDay()->toDateString()]);
        $item = $this->item(['title' => 'One']);

        Livewire::test(Backlog::class, ['project' => $this->project->getKey()])
            ->set('selected', [$item->getKey()])
            ->call('setRelease', $shipped->getKey());

        $this->assertNull($item->fresh()->release_id);
    }
}
