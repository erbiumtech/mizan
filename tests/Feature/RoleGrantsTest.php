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
     * **2026-08-17, construction Phase 1** (`docs/construction-management-plan.md`) moved them twice, and both
     * times this test failed until the numbers were changed on purpose, which is what it is for.
     *
     * Phase 1a, the four job permissions: Employee +1 (`ConstructionJobView` — a site engineer reads the job
     * they are on, and row scoping rather than the permission is what narrows it); Accountant +3 (view, create,
     * update — the commercial side maintains jobs); Manager +3, inherited with no addition of its own; CEO +4,
     * the inherited three plus `ConstructionJobDelete`, which the policy further refuses on a closed job.
     *
     * Phase 1c, the four cost-code permissions, on the same shape: Employee +1 for view, because a material
     * issue has to name a code and a picker with nothing in it is a form nobody can complete; Accountant +3,
     * because the library is what the next tender is priced from; CEO +1 for delete, which the policy further
     * refuses on a code that has children.
     *
     * Phase 2, the six cost-ledger permissions: Employee +1 for view — a site engineer sees what the job has
     * cost; Accountant +3 to record it; **Manager +2**, for `ConstructionCostReverse` and
     * `ConstructionPeriodClose`, both approval-shaped and kept away from whoever recorded the cost; CEO +1 for
     * `ConstructionPeriodForceClose`, which is closing over an unexplained difference between the two ledgers
     * and is the one that needs a name on it.
     *
     * Phase 1d, the five document permissions — and this is the run where the ratchet earned itself. Employee
     * +1 for view; Accountant +3; **Manager +1 for `ConstructionDocumentPublish`**, its first addition of its
     * own here, because publishing is what says "build this" and belongs with the approval powers rather than
     * with whoever uploads drawings; CEO +1 for delete. Working out why Accountant moved by 3 rather than 2 is
     * what surfaced a real hole: the roles are separate leaves, not a chain, so Accountant does not inherit
     * Employee's view — and without it a surveyor could upload a drawing and then not be able to open it.
     *
     * Phase 3, the six budget, measurement and forecast permissions: Employee +1 for `ConstructionBudgetView`,
     * because a figure the site team cannot see is a figure they cannot work to; **Accountant +4** — the surveyor
     * builds the budget, measures progress and prepares the forecast, which is four of the six; **Manager +1 of its
     * own** for `ConstructionBudgetApprove`, approval-shaped and kept away from whoever priced it; **CEO +1** for
     * `ConstructionBudgetBaseline`, which decides what every earned-value figure on the job is measured against and
     * restates all of them if it moves, so it sits with whoever answers for the numbers.
     *
     * Phase 4a, the five contract permissions: Employee +1 for view, because people build to the
     * specification, the dates and the damages, and a contract they cannot open is one they cannot work to;
     * Accountant +3 to raise and price one; **Manager +1 of its own** for `ConstructionContractExecute`, which
     * freezes the scheduled values every later certificate is measured against; **CEO +1** for delete, which
     * the policy further refuses on anything but an empty draft with no subcontracts under it.
     *
     * Phase 4b, the five variation permissions: Employee +1 for view, because an instruction nobody on site can
     * see is work that gets built to the superseded drawing; Accountant +4 — the surveyor raises, describes,
     * prices and reads them; **Manager +1 of its own** for `ConstructionVariationApprove`, which agrees the
     * employer's money and writes the schedule, and which the policy also requires to write an approved
     * variation in; CEO +0 of its own, inheriting all five.
     *
     * Phase 4c, the four claim-and-certificate permissions, and this is the run where the shape of the module is
     * clearest in the numbers: Employee **+0** — a certificate is a commercial instrument and a site engineer has
     * no business in one; Accountant +2 to prepare claims and draft certificates; **Manager +1 of its own** for
     * `ConstructionCertificateCertify`, which starts a payment period and creates an entitlement the other party
     * will enforce; **CEO +1 of its own** for `ConstructionCertificateInvoice`, the act that moves the figure into
     * the books.
     *
     * Phase 5b, the three requisition permissions, and this is the run where the shape of the suite changes:
     * **Employee +2, one of them a `create`** — the first and only one in the construction suite. The demand
     * document exists because the demand comes from the people who need the material, and a requisition raised only
     * by the commercial office is a purchase order with an extra step. Accountant +2 to raise and read them;
     * **Manager +1 of its own** for `ConstructionRequisitionApprove`, which agrees the need is real and still
     * commits nothing — the money moves at `ConstructionCommitmentIssue`, two grants later.
     *
     * Manager and CEO gain **two** rather than three, and working out why is the useful part: the roles are separate
     * leaves rather than a chain, so neither inherits Employee's `ConstructionRequisitionCreate`. They get the two
     * the Accountant has plus their own approval — a manager who needs to raise a request holds the Accountant's
     * grant, not the site engineer's.
     *
     * Phase 5a, the five commitment permissions: Employee +1 for view, because "has the rebar been ordered" is a
     * site question and an invisible answer produces a second order for it; Accountant +2 to raise and price one;
     * **Manager +2 of its own** for `ConstructionCommitmentApprove` and `ConstructionCommitmentIssue`, which are two
     * decisions rather than one — approving spends the company's money, issuing commits it to a supplier and is what
     * puts the figure on the cost report; **CEO +1 of its own** for `ConstructionCommitmentClose`, which writes off
     * money somebody committed and needs a name against it.
     *
     * Phase 4d, one permission: **`ConstructionRetentionRelease` on Manager**. Reading the ledger rides on
     * `ConstructionCertificateView` — same audience, same screenful — but releasing hands back money the contract
     * entitled the company to hold, which on a job of any size is the largest single payment decision anybody
     * makes, and forfeiting takes money the other party earned.
     *
     * @var array<string, int>
     */
    private const EXPECTED = [
        'Employee' => 37,
        'Accountant' => 110,
        'Manager' => 134,
        'CEO' => 154,
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
