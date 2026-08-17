<?php

namespace Tests\Feature;

use App\Support\ModuleManifest;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The five roles, composed from what the modules grant.
 *
 * `RoleSeeder` held ~150 grants as literal per-role lists, which made adding a module an edit to a central
 * file — the last of the six that phase 3 set out to remove, and the one it left behind: `ModuleManifest` has
 * had the `role_grants` merge slot since then with nothing filling it. See docs/module-packaging-plan.md §5.
 *
 * The refactor was proved behaviour-preserving by snapshotting every role's permission set before and after
 * and diffing them. What this file protects is the part a snapshot cannot: that the *composition* still holds
 * once the lists are spread across 15 manifests, where nobody reads them together.
 *
 * The counts are asserted deliberately: a change to any of them means somebody has widened or narrowed a role,
 * which is a decision worth failing on rather than a detail. Update them in the same commit as the grant, and
 * say why — the construction entry on `EXPECTED` is what that looks like, and it is the mechanism working
 * rather than an inconvenience.
 */
class RoleGrantsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /**
     * What each role holds, and every change to these numbers is a decision.
     *
     * The baseline was 27 / 81 / 94 / 106 — what the pre-refactor seeder produced, snapshotted and diffed to
     * prove the move into the manifests changed nothing.
     *
     * **2026-08-17, construction Phase 1** (`docs/construction-management-plan.md`): the `construction`
     * module's four job permissions were granted, and this test failed until the counts were changed on
     * purpose, which is what it is for. Employee +1 (`ConstructionJobView` — a site engineer reads the job
     * they are on, and row scoping rather than the permission is what narrows it); Accountant +3 (view,
     * create, update — the commercial side maintains jobs); Manager +3, inherited from Accountant with no
     * addition of its own; CEO +4, the inherited three plus `ConstructionJobDelete`, which the policy further
     * refuses on a closed job.
     *
     * @var array<string, int>
     */
    private const EXPECTED = [
        'Employee' => 28,
        'Accountant' => 84,
        'Manager' => 97,
        'CEO' => 110,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'rolegrants@test.local'));
        $this->setCurrentTenant();

        (new RoleSeeder)->run();
    }

    /** @return array<int, string> */
    private function permissionsOf(string $role): array
    {
        return Role::query()
            ->with('permissions')
            ->where('name', $role)
            ->firstOrFail()
            ->permissions
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    public function test_every_role_holds_exactly_the_permissions_it_is_meant_to(): void
    {
        foreach (self::EXPECTED as $role => $count) {
            $this->assertCount($count, $this->permissionsOf($role), "{$role} changed size");
        }
    }

    /** Administrator is defined as "everything", so it needs no declarations and never goes stale. */
    public function test_administrator_holds_every_permission(): void
    {
        $this->assertSame(
            Permission::query()->pluck('name')->sort()->values()->all(),
            $this->permissionsOf('Administrator'),
        );
    }

    /**
     * The composition a module cannot express.
     *
     * Manager is Accountant plus approvals; CEO is Manager plus deletions. A module contributes only its
     * additions at each rung, so if this chain broke, the two senior roles would silently lose everything the
     * junior one holds — and every approval screen would 403 for the people meant to use it.
     */
    public function test_manager_and_ceo_are_supersets_of_the_role_below(): void
    {
        $accountant = $this->permissionsOf('Accountant');
        $manager = $this->permissionsOf('Manager');
        $ceo = $this->permissionsOf('CEO');

        $this->assertSame([], array_diff($accountant, $manager), 'Manager lost something Accountant holds');
        $this->assertSame([], array_diff($manager, $ceo), 'CEO lost something Manager holds');

        // And each rung genuinely adds something, or the composition is decorative.
        $this->assertNotEmpty(array_diff($manager, $accountant));
        $this->assertNotEmpty(array_diff($ceo, $manager));
    }

    /**
     * Segregation of duties: the Accountant records and does not approve, post or reverse.
     *
     * The reason the middle three roles exist at all, and the one property of this file worth reading if
     * something here fails.
     */
    public function test_the_accountant_cannot_approve_post_or_reverse(): void
    {
        $accountant = $this->permissionsOf('Accountant');

        foreach (['JournalEntryApprove', 'JournalEntryPost', 'JournalEntryReverse', 'JournalEntryReject'] as $name) {
            $this->assertNotContains($name, $accountant, "the Accountant must not hold {$name}");
            $this->assertContains($name, $this->permissionsOf('Manager'), "the Manager must hold {$name}");
        }
    }

    /**
     * Deleting a ledger transaction is Administrator-only, even for the CEO.
     *
     * The CEO corrects the books by reversing, which leaves both rows on the ledger. This was a comment in
     * the seeder and is now an assertion, because it is the kind of decision a later grant undoes by accident.
     */
    public function test_not_even_the_ceo_deletes_a_journal_entry(): void
    {
        $this->assertNotContains('JournalEntryDelete', $this->permissionsOf('CEO'));
        $this->assertContains('JournalEntryDelete', $this->permissionsOf('Administrator'));
    }

    /**
     * A sales pipeline is not something every member of staff has.
     *
     * The one decision no module can declare — an absence — so it is asserted here rather than left to a
     * comment. See RoleSeeder's docblock.
     */
    public function test_the_employee_role_has_no_crm_access(): void
    {
        $employee = $this->permissionsOf('Employee');

        foreach ($employee as $name) {
            $this->assertStringNotContainsString('Lead', $name, 'CRM reached the Employee role');
        }
    }

    /** A module may only grant what it declares — the check that replaces the central list's implicit one. */
    public function test_no_module_grants_a_permission_it_does_not_own(): void
    {
        $manifest = ModuleManifest::all();
        $groupOwner = [];

        foreach ($manifest['permission_groups'] ?? [] as $module => $groups) {
            foreach ($groups as $group) {
                $groupOwner[$group] = $module;
            }
        }

        $owner = [];

        foreach ($manifest['permissions'] ?? [] as $permission) {
            $owner[$permission['name']] = $groupOwner[$permission['group']] ?? null;
        }

        $problems = [];

        foreach (ModuleManifest::manifestPaths() as $module => $path) {
            foreach ((require $path)['role_grants'] ?? [] as $role => $names) {
                foreach ($names as $name) {
                    if (($owner[$name] ?? null) !== $module) {
                        $problems[] = "{$module} grants {$name} to {$role} but does not declare it";
                    }
                }
            }
        }

        $this->assertSame([], $problems);
    }

    /**
     * The grants actually come from the manifests.
     *
     * Guards the guard: every assertion above would still pass if somebody restored the literal lists to
     * RoleSeeder, and the central edit would be back with the suite green.
     */
    public function test_the_grants_are_declared_by_modules_rather_than_by_the_seeder(): void
    {
        $declaring = [];

        foreach (ModuleManifest::manifestPaths() as $module => $path) {
            if ((require $path)['role_grants'] ?? [] !== []) {
                $declaring[] = $module;
            }
        }

        $this->assertGreaterThanOrEqual(15, count($declaring), 'the manifests stopped carrying the grants');

        $source = file_get_contents((new \ReflectionClass(RoleSeeder::class))->getFileName());

        // The seeder names the four composed roles and Administrator, and no permission at all except the
        // four it asserts the Accountant must not hold — which live in this test, not there.
        $this->assertStringNotContainsString("'PayslipView'", $source, 'a literal grant is back in RoleSeeder');
        $this->assertStringNotContainsString("'AccountView'", $source, 'a literal grant is back in RoleSeeder');
    }
}
