<?php

namespace Tests\Feature;

use App\Modules\Construction\Filament\Resources\Jobs\JobResource;
use App\Modules\Construction\Models\Job;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Invoicing\Models\Contact;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Jobs — the spine of the construction suite.
 *
 * `docs/construction-management-plan.md` §1: a job is one contract to build one thing at one place, and
 * everything else in that plan hangs off it. Phase 1.
 *
 * What is asserted here is the part that is easy to get wrong and invisible when it is: the materialised
 * `path` that every rolled-up report reads (§1.2), and the guarded Invoicing coupling that keeps the module
 * sellable to a contractor whose books are somewhere else (§18).
 */
class ConstructionJobTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'construction@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'invoicing'] as $module) {
            $this->setModule($module, true);
        }
    }

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function job(string $code, array $attributes = []): Job
    {
        return Job::create(array_merge([
            'code' => $code,
            'name' => "Job {$code}",
        ], $attributes));
    }

    public function test_a_job_can_be_created(): void
    {
        $job = $this->job('J-2026-001', ['contract_sum' => 250_000_000, 'retention_pct' => 10]);

        $this->assertTrue($job->exists);
        $this->assertSame(Job::STATUS_TENDER, $job->status, 'a job starts at tender');
        $this->assertSame(Job::STANDARD_FIDIC, $job->contract_standard);
    }

    // ---------------------------------------------------------------- the tree

    /**
     * The path is `/self/` at the root and `/parent/self/` below it.
     *
     * Maintained on save so no caller has to remember, because a stale path is a cost report that silently
     * omits a lot — the failure §1.2's rollup depends on not happening.
     */
    public function test_a_root_job_paths_to_itself(): void
    {
        $job = $this->job('J-2026-001');

        $this->assertSame("/{$job->getKey()}/", $job->fresh()->path);
    }

    public function test_a_sub_job_paths_through_its_parent(): void
    {
        $parent = $this->job('J-2026-001');
        $tower = $this->job('J-2026-001-A', ['parent_id' => $parent->getKey()]);

        $this->assertSame(
            "/{$parent->getKey()}/{$tower->getKey()}/",
            $tower->fresh()->path,
            'a sub-job must sit under its parent, or a rolled-up report misses it',
        );
    }

    /** Three levels, because a development with towers and a tower with phases is the ordinary case. */
    public function test_the_path_survives_a_third_level(): void
    {
        $development = $this->job('J-2026-001');
        $tower = $this->job('J-2026-001-A', ['parent_id' => $development->getKey()]);
        $phase = $this->job('J-2026-001-A-1', ['parent_id' => $tower->getKey()]);

        $this->assertSame(
            "/{$development->getKey()}/{$tower->getKey()}/{$phase->getKey()}/",
            $phase->fresh()->path,
        );
    }

    /**
     * `inSubtree()` is what a rolled-up report reads, and it includes the root.
     *
     * The board asks for the consolidated cost of a development; the per-lot certificate needs the lot alone.
     * Both come off this scope with a different root, which is the whole reason §1.2 exists.
     */
    public function test_in_subtree_returns_the_root_and_its_descendants_only(): void
    {
        $development = $this->job('J-2026-001');
        $tower = $this->job('J-2026-001-A', ['parent_id' => $development->getKey()]);
        $unrelated = $this->job('J-2026-002');

        $found = Job::query()->inSubtree($development->fresh())->pluck('code')->sort()->values()->all();

        $this->assertSame(['J-2026-001', 'J-2026-001-A'], $found);
        $this->assertNotContains($unrelated->code, $found);
    }

    // ---------------------------------------------------------------- dates

    /**
     * The completion date in force is the revised one.
     *
     * A screen reading `planned_completion_date` after an approved extension of time shows a job as late when
     * it is not, which is an argument with a client rather than a display bug.
     */
    public function test_the_completion_date_in_force_is_the_revised_one(): void
    {
        $job = $this->job('J-2026-001', [
            'planned_completion_date' => '2027-06-30',
            'revised_completion_date' => '2027-09-30',
        ]);

        $this->assertSame('2027-09-30', $job->completionDate()->toDateString());
    }

    public function test_without_an_extension_the_planned_date_stands(): void
    {
        $job = $this->job('J-2026-001', ['planned_completion_date' => '2027-06-30']);

        $this->assertSame('2027-06-30', $job->completionDate()->toDateString());
    }

    // ---------------------------------------------------------------- scoping

    public function test_live_excludes_closed_cancelled_and_lost(): void
    {
        $this->job('J-2026-001', ['status' => Job::STATUS_IN_PROGRESS]);
        $this->job('J-2026-002', ['status' => 'closed']);
        $this->job('J-2026-003', ['status' => 'lost']);
        $this->job('J-2026-004', ['status' => 'cancelled']);

        $this->assertSame(['J-2026-001'], Job::query()->live()->pluck('code')->all());
    }

    // ---------------------------------------------------------------- the module boundary

    /**
     * A job names a client Contact, and Invoicing is guarded rather than required.
     *
     * §18's decision is that `construction` requires nothing, so this is the assertion that the decision is
     * real: with Invoicing off, the pickers are gone and a job still saves. Without it the registry claim is
     * theoretical.
     */
    public function test_a_job_still_saves_with_invoicing_switched_off(): void
    {
        $this->setModule('invoicing', false);

        $job = $this->job('J-2026-009');

        $this->assertTrue($job->exists);
        $this->assertNull($job->client_contact_id);
    }

    public function test_the_client_picker_is_absent_without_invoicing(): void
    {
        $this->setModule('invoicing', false);

        Livewire::test(JobResource::getPages()['create']->getPage())
            ->assertSuccessful()
            ->assertDontSee('Certifier');
    }

    public function test_the_client_picker_is_there_with_invoicing(): void
    {
        Livewire::test(JobResource::getPages()['create']->getPage())
            ->assertSuccessful()
            ->assertSee('Certifier');
    }

    /** The client relation resolves, which is what the list column reads. */
    public function test_a_job_reads_its_client(): void
    {
        $client = Contact::create(['name' => 'Ministry of Works', 'kind' => Contact::KIND_CUSTOMER]);

        $job = $this->job('J-2026-001', ['client_contact_id' => $client->getKey()]);

        $this->assertSame('Ministry of Works', $job->client->name);
    }

    // ---------------------------------------------------------------- the screens

    public function test_the_job_list_renders(): void
    {
        $this->job('J-2026-001', ['status' => Job::STATUS_IN_PROGRESS]);

        Livewire::test(JobResource::getPages()['index']->getPage())
            ->assertSuccessful()
            ->assertSee('J-2026-001');
    }

    /** The default view is live jobs: a contractor's closed and lost list grows forever. */
    public function test_the_list_defaults_to_live_jobs(): void
    {
        $this->job('J-2026-001', ['status' => Job::STATUS_IN_PROGRESS]);
        $this->job('J-2026-002', ['status' => 'lost']);

        Livewire::test(JobResource::getPages()['index']->getPage())
            ->assertSee('J-2026-001')
            ->assertDontSee('J-2026-002');
    }
}
