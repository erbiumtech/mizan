<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Services\LibraryInstaller;
use App\Modules\Core\Models\CompanyModule;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A company licensed for construction must not arrive at the job-cost screens unable to book a cost.
 *
 * `CostLedger::record()` requires a leaf cost code, and nothing seeded one — so the module shipped in a state
 * where every costing action refused until somebody hand-built a tree through the UI. This is the guarantee
 * that closes it, and the command is deliberately the vehicle: construction is
 * `'licensed_by_default' => false`, so `$tenantSeeders` runs before the module is ever on and a guarded
 * seeder there would skip every company forever.
 *
 * These exercise `LibraryInstaller` rather than the command. The command is `TenantAware`, which switches
 * tenant databases, and this suite runs every tenant in one — `SwitchTenantDatabaseTask` throws the moment a
 * test invokes it. **So the command's own tenant loop, its `--tenant` filter and its `--dry-run` branch are
 * not covered here**, and were verified by running it across all six local tenants instead.
 */
class ConstructionLibraryInstallTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private function license(string ...$modules): void
    {
        foreach ($modules as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
    }

    /**
     * Explicitly not bought, which is what an unlicensed company actually looks like.
     *
     * A real company has a row for every module — the platform panel writes them all — so "unlicensed" is
     * `licensed = false`, not an absent row. An absent row falls back to the manifest's
     * `licensed_by_default`, which is a different question from this one.
     */
    private function unlicense(string ...$modules): void
    {
        foreach ($modules as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => false, 'enabled' => false],
            );
        }

        modules()->flush();
    }

    private function installer(): LibraryInstaller
    {
        return app(LibraryInstaller::class);
    }

    /** What the command does per tenant, minus the tenant switching the suite cannot do. */
    private function install(): void
    {
        $installer = $this->installer();

        if ($installer->isLicensed()) {
            $installer->install();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'library@test.local'));
        $this->setCurrentTenant();
    }

    public function test_a_company_licensed_for_construction_can_book_a_cost_afterwards(): void
    {
        $this->license('construction');

        $this->assertSame(0, CostCode::count(), 'the gap this closes: a licensed company starts with nothing');

        $this->install();

        // Bookable means active and not a heading — exactly what CostLedger::record() will accept.
        $this->assertSame(36, CostCode::query()->bookable()->count());
        $this->assertTrue(CostCode::where('code', '01.100')->sole()->is_leaf, 'land is bookable');
    }

    /** Reference data, so running it twice must update rather than duplicate. */
    public function test_it_is_idempotent(): void
    {
        $this->license('construction');

        $this->install();
        $first = CostCode::count();

        $this->install();

        $this->assertSame($first, CostCode::count());
    }

    /**
     * A company that has not bought construction gets nothing, which is what makes running this across every
     * tenant reasonable rather than reckless.
     */
    public function test_an_unlicensed_company_is_left_alone(): void
    {
        $this->unlicense('construction');

        $this->install();

        $this->assertSame(0, CostCode::count());
    }

    /**
     * The accounts follow costing, not construction.
     *
     * §18.2: retention receivable and the recovery accounts are accounting rows, and a contractor keeping its
     * books elsewhere has no use for them — which is the same argument that keeps them out of every
     * company's chart in the first place.
     */
    public function test_the_construction_accounts_follow_the_costing_licence(): void
    {
        $this->license('construction');
        $this->unlicense('construction_costing');
        $this->install();

        $this->assertSame(0, Account::whereIn('code', ['1600', '1610', '1620'])->count());

        $this->license('construction_costing');
        $this->install();

        $this->assertSame(3, Account::whereIn('code', ['1600', '1610', '1620'])->count());
    }

    /** Reading the plan must not write it — that is what the command's `--dry-run` relies on. */
    public function test_inspecting_a_company_writes_nothing(): void
    {
        $this->license('construction', 'construction_costing');

        $installer = $this->installer();

        $this->assertTrue($installer->isLicensed());
        $this->assertTrue($installer->wantsAccounts());
        $this->assertSame(0, $installer->existingCodes());

        $this->assertSame(0, CostCode::count());
        $this->assertSame(0, Account::whereIn('code', ['1600', '1610', '1620'])->count());
    }
}
