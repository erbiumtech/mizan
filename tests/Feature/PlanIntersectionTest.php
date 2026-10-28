<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Multitenancy\CompanyProvisioner;
use App\Support\CompanyProfiles;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * A plan on a real company: what provisioning licenses, and what an upgrade
 * changes — and, more to the point, what it leaves alone.
 * docs/saas-launch-plan.md §3.
 */
class PlanIntersectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['multitenancy.tenant_database_connection_name' => 'tenant']);
        $this->seed(PermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();

        foreach (Company::all() as $company) {
            if ($company->database && File::exists($company->database)) {
                File::delete($company->database);
            }
        }

        parent::tearDown();
    }

    public function test_a_restaurant_on_starter_is_licensed_the_intersection_and_recommended_the_rest(): void
    {
        $company = $this->provision('Chai Khana', 'restaurant', 'starter');

        modules()->flush();

        $this->assertSame('starter', $company->plan);

        // What both the profile and the plan agree on: licensed and usable.
        foreach (['employees', 'attendance', 'leave'] as $module) {
            $this->assertTrue(modules()->licensedFor($company->getKey(), $module), "Starter did not license [{$module}].");
            $this->assertTrue(modules()->enabledFor($company->getKey(), $module), "[{$module}] is licensed but cannot be used.");
        }

        // Inventory: the profile recommends it, Starter does not sell it. So it
        // is recommended — the Modules page's "upgrade to license it" — and it
        // cannot be switched on, because licensedFor() is false and the
        // activation page lists only what activatable() returns.
        $this->assertTrue(CompanyProfiles::recommends('restaurant', 'inventory'));
        $this->assertFalse(modules()->licensedFor($company->getKey(), 'inventory'));
        $this->assertNotContains('inventory', modules()->activatable($company->getKey()));
        $this->assertFalse(modules()->enabledFor($company->getKey(), 'inventory'));

        // And a module Starter sells but the profile never recommends is not
        // licensed either — the intersection, not the plan.
        $this->assertFalse(modules()->licensedFor($company->getKey(), 'mpr'));
    }

    public function test_upgrading_licenses_what_the_new_plan_adds_without_touching_what_was_switched_off(): void
    {
        $company = $this->provision('Chai Khana', 'restaurant', 'starter');

        // The company's own Administrator switched leave off. That choice is
        // theirs and has to survive anything the platform does to licences.
        CompanyModule::where('company_id', $company->getKey())->where('module', 'leave')
            ->update(['enabled' => false]);

        // And a super admin sold them projects outside the profile — a
        // deliberate sale that an upgrade must not undo.
        CompanyModule::updateOrCreate(
            ['company_id' => $company->getKey(), 'module' => 'projects'],
            ['licensed' => true, 'enabled' => null],
        );

        modules()->flush();

        $company->changePlan('business');
        $company->refresh();

        $this->assertSame('business', $company->plan);

        // Inventory and the ledger it requires are now licensed and usable,
        // with no visit to the Modules page: NULL means the first grant lights
        // the module up.
        foreach (['inventory', 'accounting', 'invoicing', 'crm', 'payroll'] as $module) {
            $this->assertTrue(modules()->licensedFor($company->getKey(), $module), "Business did not license [{$module}].");
            $this->assertTrue(modules()->enabledFor($company->getKey(), $module), "[{$module}] is licensed but cannot be used.");
        }

        // Leave is still licensed and still off — their choice, untouched.
        $this->assertTrue(modules()->licensedFor($company->getKey(), 'leave'));
        $this->assertFalse(modules()->enabledFor($company->getKey(), 'leave'));
        $this->assertFalse(
            CompanyModule::where('company_id', $company->getKey())->where('module', 'leave')->value('enabled'),
        );

        // Projects survives: the union, never the difference.
        $this->assertTrue(modules()->licensedFor($company->getKey(), 'projects'));

        // Still the intersection: Business sells mpr, the profile does not
        // recommend it, so it is not granted.
        $this->assertFalse(modules()->licensedFor($company->getKey(), 'mpr'));

        // Every grant is on the audit trail, in the platform panel's words.
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => $company->getMorphClass(),
            'subject_id' => $company->getKey(),
            'description' => 'Granted the Inventory module licence',
        ]);
    }

    public function test_a_company_without_a_plan_is_provisioned_exactly_as_before(): void
    {
        $company = $this->provision('No Plan Ltd', 'restaurant', null);

        modules()->flush();

        $this->assertNull($company->plan);

        // The whole profile, as CompanyProfileTest already pins for a company
        // that predates plans.
        foreach (CompanyProfiles::modules('restaurant') as $module) {
            $this->assertTrue(modules()->licensedFor($company->getKey(), $module));
        }
    }

    public function test_an_unknown_plan_is_refused_and_leaves_no_company_behind(): void
    {
        try {
            $this->provision('Platinum Ltd', 'restaurant', 'platinum');
            $this->fail('An unknown plan was accepted.');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/no plan \[platinum\]/', $e->getMessage());
        }

        $this->assertSame(0, Company::where('name', 'Platinum Ltd')->count(), 'A rejected plan left an orphan company row.');
    }

    private function provision(string $name, ?string $profile, ?string $plan): Company
    {
        return app(CompanyProvisioner::class)->provision(
            name: $name,
            creator: User::factory()->create(['status' => 1]),
            type: Company::TYPE_BUSINESS,
            profile: $profile,
            plan: $plan,
        );
    }
}
