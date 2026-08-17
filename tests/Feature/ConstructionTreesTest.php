<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\Core\Models\CompanyModule;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The two per-job trees: the work breakdown and the location register.
 *
 * `docs/construction-management-plan.md` §2 and §16.5, Phase 1b. Both use `HasMaterialisedPath`, and what this
 * file is really testing is that trait — because the same subtle lifecycle bug is available three times and
 * was hit once already on `Job`: a path written in `saving` has no id yet, so every root gets `/` and the
 * subtree scope silently matches the whole table.
 *
 * The partition is the other half. A WBS node and a location belong to one job, and a subtree query that
 * crossed jobs would roll one job's cost into another's report — which is a wrong number that looks right, the
 * class of failure this whole plan keeps guarding against.
 */
class ConstructionTreesTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private Job $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'trees@test.local'));
        $this->setCurrentTenant();

        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'construction'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->other = Job::create(['code' => 'J-2', 'name' => 'Bridge']);
    }

    private function node(string $code, string $name, ?WbsNode $parent = null, ?Job $job = null): WbsNode
    {
        return WbsNode::create([
            'job_id' => ($job ?? $this->job)->getKey(),
            'parent_id' => $parent?->getKey(),
            'code' => $code,
            'name' => $name,
        ]);
    }

    private function place(string $name, string $type, ?Location $parent = null, ?Job $job = null): Location
    {
        return Location::create([
            'job_id' => ($job ?? $this->job)->getKey(),
            'parent_id' => $parent?->getKey(),
            'name' => $name,
            'type' => $type,
        ]);
    }

    // ---------------------------------------------------------------- the WBS

    public function test_a_wbs_node_paths_from_its_parent(): void
    {
        $phase = $this->node('1', 'Substructure');
        $element = $this->node('1.1', 'Piling', $phase);

        $this->assertSame("/{$phase->getKey()}/", $phase->fresh()->path);
        $this->assertSame("/{$phase->getKey()}/{$element->getKey()}/", $element->fresh()->path);
    }

    /**
     * A node that gains a child stops being bookable.
     *
     * A parent carrying cost *and* children makes every rollup count it twice — the one arithmetic error this
     * tree can produce with nothing reporting it.
     */
    public function test_a_parent_stops_being_a_leaf(): void
    {
        $phase = $this->node('1', 'Substructure');

        $this->assertTrue($phase->fresh()->is_leaf, 'a node with no children is bookable');

        $this->node('1.1', 'Piling', $phase);

        $this->assertFalse($phase->fresh()->is_leaf, 'a parent must stop being bookable');
    }

    /** The code is unique per job, not per company: 1.2.3 means something different on every job. */
    public function test_the_same_code_may_exist_on_two_jobs(): void
    {
        $this->node('1.1', 'Piling');
        $onOther = $this->node('1.1', 'Deck slab', null, $this->other);

        $this->assertTrue($onOther->exists);
        $this->assertSame('Deck slab', $onOther->name);
    }

    /**
     * A subtree never crosses a job, and this is the assertion the partition exists for.
     *
     * Two jobs' trees share one table, so a `LIKE '/1/%'` without the job filter would match whichever nodes
     * happened to prefix-match — rolling one job's cost into another's report.
     */
    public function test_a_wbs_subtree_never_crosses_into_another_job(): void
    {
        $phase = $this->node('1', 'Substructure');
        $this->node('1.1', 'Piling', $phase);

        // A node on the other job, deliberately given a path that could prefix-match.
        $this->node('1', 'Abutments', null, $this->other);

        $found = WbsNode::query()->inSubtree($phase->fresh())->pluck('name')->sort()->values()->all();

        $this->assertSame(['Piling', 'Substructure'], $found);
        $this->assertNotContains('Abutments', $found);
    }

    public function test_depth_is_counted_from_the_path(): void
    {
        $phase = $this->node('1', 'Substructure');
        $element = $this->node('1.1', 'Piling', $phase);
        $package = $this->node('1.1.1', 'CFA piles', $element);

        $this->assertSame(0, $phase->fresh()->depth());
        $this->assertSame(1, $element->fresh()->depth());
        $this->assertSame(2, $package->fresh()->depth());
    }

    /** Moving a node takes its subtree with it, or every descendant's rollup is wrong from then on. */
    public function test_moving_a_node_repaths_its_descendants(): void
    {
        $first = $this->node('1', 'Substructure');
        $second = $this->node('2', 'Superstructure');
        $element = $this->node('1.1', 'Piling', $first);
        $package = $this->node('1.1.1', 'CFA piles', $element);

        $element->update(['parent_id' => $second->getKey()]);

        $this->assertSame(
            "/{$second->getKey()}/{$element->getKey()}/",
            $element->fresh()->path,
        );
        $this->assertSame(
            "/{$second->getKey()}/{$element->getKey()}/{$package->getKey()}/",
            $package->fresh()->path,
            'a grandchild kept the old path, so its cost rolls up to the wrong parent',
        );
    }

    /**
     * A node that gives up its last child becomes bookable again.
     *
     * The symmetric half of the leaf flag, and the easier one to forget: leaving a childless node flagged as a
     * heading hides it from every budget picker with no explanation on screen. Found while testing the cost-code
     * import, which creates rows as roots and links parents afterwards — so the `created` hook alone left
     * imported headings looking bookable and moved-from parents looking like headings.
     */
    public function test_a_node_that_loses_its_last_child_becomes_bookable_again(): void
    {
        $first = $this->node('1', 'Substructure');
        $second = $this->node('2', 'Superstructure');
        $element = $this->node('1.1', 'Piling', $first);

        $this->assertFalse($first->fresh()->is_leaf);

        $element->update(['parent_id' => $second->getKey()]);

        $this->assertTrue($first->fresh()->is_leaf, 'the old parent has no children and must be bookable again');
        $this->assertFalse($second->fresh()->is_leaf, 'the new parent must stop being bookable');
    }

    /** A node with two children keeps its heading status when one of them leaves. */
    public function test_a_node_with_another_child_stays_a_heading(): void
    {
        $first = $this->node('1', 'Substructure');
        $second = $this->node('2', 'Superstructure');
        $moving = $this->node('1.1', 'Piling', $first);
        $this->node('1.2', 'Pile caps', $first);

        $moving->update(['parent_id' => $second->getKey()]);

        $this->assertFalse($first->fresh()->is_leaf, 'it still has a child, so it is still a heading');
    }

    public function test_a_node_labels_itself_as_code_and_name(): void
    {
        $this->assertSame('1.1 Piling', $this->node('1.1', 'Piling')->label());
    }

    // ---------------------------------------------------------------- locations

    public function test_a_location_paths_from_its_parent(): void
    {
        $building = $this->place('Tower B', Location::TYPE_BUILDING);
        $level = $this->place('Level 4', Location::TYPE_LEVEL, $building);

        $this->assertSame("/{$building->getKey()}/{$level->getKey()}/", $level->fresh()->path);
    }

    /**
     * The full name is the whole place, top-down.
     *
     * "Room 412" is ambiguous the moment a second tower has one, and this is the string every punch list,
     * inspection and incident screen needs.
     */
    public function test_a_location_names_itself_from_the_top_down(): void
    {
        $building = $this->place('Tower B', Location::TYPE_BUILDING);
        $level = $this->place('Level 4', Location::TYPE_LEVEL, $building);
        $room = $this->place('Room 412', Location::TYPE_ROOM, $level);

        $this->assertSame('Tower B › Level 4 › Room 412', $room->fresh()->fullName());
    }

    public function test_a_root_location_names_itself(): void
    {
        $site = $this->place('Main site', Location::TYPE_SITE);

        $this->assertSame('Main site', $site->fresh()->fullName());
    }

    /**
     * "Every open item in this building" — the question straight after "in this room".
     *
     * §16.5 lists no path column; one is added because without it this is a recursive query on the hottest
     * screen before handover.
     */
    public function test_a_location_subtree_rolls_up_a_building(): void
    {
        $building = $this->place('Tower B', Location::TYPE_BUILDING);
        $level = $this->place('Level 4', Location::TYPE_LEVEL, $building);
        $this->place('Room 412', Location::TYPE_ROOM, $level);
        $this->place('Tower C', Location::TYPE_BUILDING);

        $found = Location::query()->inSubtree($building->fresh())->pluck('name')->sort()->values()->all();

        $this->assertSame(['Level 4', 'Room 412', 'Tower B'], $found);
    }

    public function test_a_location_subtree_never_crosses_into_another_job(): void
    {
        $building = $this->place('Tower B', Location::TYPE_BUILDING);
        $this->place('Abutment 1', 'structure', null, $this->other);

        $found = Location::query()->inSubtree($building->fresh())->pluck('name')->all();

        $this->assertSame(['Tower B'], $found);
    }

    // ---------------------------------------------------------------- both

    /** Children come back in the order somebody chose, not in insertion order. */
    public function test_children_follow_sort_order(): void
    {
        $phase = $this->node('1', 'Substructure');

        WbsNode::create(['job_id' => $this->job->getKey(), 'parent_id' => $phase->getKey(), 'code' => '1.2', 'name' => 'Second', 'sort_order' => 20]);
        WbsNode::create(['job_id' => $this->job->getKey(), 'parent_id' => $phase->getKey(), 'code' => '1.1', 'name' => 'First', 'sort_order' => 10]);

        $this->assertSame(['First', 'Second'], $phase->children()->pluck('name')->all());
    }

    /** Deleting a job takes its trees with it: neither is meaningful without the job. */
    public function test_deleting_a_job_removes_its_trees(): void
    {
        $this->node('1', 'Substructure');
        $this->place('Tower B', Location::TYPE_BUILDING);

        $this->job->delete();

        $this->assertSame(0, WbsNode::query()->where('job_id', $this->job->getKey())->count());
        $this->assertSame(0, Location::query()->where('job_id', $this->job->getKey())->count());
    }
}
