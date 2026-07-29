<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two-flag model: a module is available only when a super admin has licensed
 * it *and* the company has switched it on.
 */
class ModuleStateTest extends TestCase
{
    use RefreshDatabase;

    private function company(array $rows = []): Company
    {
        $company = Company::factory()->create();

        CompanyModule::where('company_id', $company->getKey())->delete();

        foreach ($rows as $module => [$licensed, $enabled]) {
            CompanyModule::create([
                'company_id' => $company->getKey(),
                'module' => $module,
                'licensed' => $licensed,
                'enabled' => $enabled,
            ]);
        }

        modules()->flush();

        return $company;
    }

    public function test_a_module_needs_both_flags(): void
    {
        $company = $this->company([
            'accounting' => [true, true],
            'payroll' => [true, false],
            'invoicing' => [false, true],
            'inventory' => [false, false],
        ]);

        $id = $company->getKey();

        $this->assertTrue(modules()->enabledFor($id, 'accounting'), 'licensed + enabled');
        $this->assertFalse(modules()->enabledFor($id, 'payroll'), 'licensed but switched off');
        $this->assertFalse(modules()->enabledFor($id, 'invoicing'), 'switched on but not licensed');
        $this->assertFalse(modules()->enabledFor($id, 'inventory'), 'neither');
    }

    public function test_licensed_is_reported_separately_from_enabled(): void
    {
        // The activation page lists licensed modules, including ones the company
        // has switched off — otherwise a module they paid for and hid could never
        // be brought back.
        $company = $this->company(['payroll' => [true, false]]);

        $this->assertTrue(modules()->licensedFor($company->getKey(), 'payroll'));
        $this->assertFalse(modules()->enabledFor($company->getKey(), 'payroll'));
        $this->assertContains('payroll', modules()->activatable($company->getKey()));
    }

    public function test_a_module_with_no_row_falls_back_to_its_shipped_default(): void
    {
        // This is what makes a module added in a later release appear for existing
        // companies with the default it shipped with, instead of being absent.
        $company = $this->company();

        $this->assertTrue(modules()->enabledFor($company->getKey(), 'core'), 'core ships on');
        $this->assertFalse(modules()->enabledFor($company->getKey(), 'accounting'), 'accounting ships off');
    }

    public function test_without_a_current_company_every_module_reads_as_available(): void
    {
        // Deliberate: outside a tenant the tenant connection is not even switched,
        // so there is no company to licence and no tenant data to protect. Landlord
        // surfaces are all Core; commands resolve their company via enabledFor().
        Company::forgetCurrent();
        modules()->flush();

        $this->assertTrue(modules()->enabled('accounting'));
        $this->assertTrue(modules()->enabled('payroll'));
    }

    public function test_core_stays_available_even_when_explicitly_switched_off(): void
    {
        $company = $this->company(['core' => [false, false]]);

        $this->assertTrue(modules()->enabledFor($company->getKey(), 'core'));
    }

    public function test_state_is_read_once_per_company_per_request(): void
    {
        $company = $this->company(['accounting' => [true, true]]);
        $id = $company->getKey();

        modules()->flush();
        DB::enableQueryLog();

        for ($i = 0; $i < 5; $i++) {
            modules()->enabledFor($id, 'accounting');
            modules()->enabledFor($id, 'payroll');
        }

        $queries = collect(DB::getRawQueryLog())
            ->filter(fn (array $entry) => str_contains($entry['raw_query'], 'company_modules'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(1, $queries, 'The per-company map must be loaded once, not per canAccess() call.');
    }

    public function test_dependents_are_reported_transitively(): void
    {
        // Disabling Accounting must report Invoicing and Inventory, which is what
        // the admin form warns about before saving.
        $dependents = Modules::dependents('accounting');

        $this->assertContains('invoicing', $dependents);
        $this->assertContains('inventory', $dependents);
        $this->assertNotContains('payroll', $dependents, 'Payroll posts through Accounting but degrades instead.');
    }

    public function test_missing_requirements_are_listed(): void
    {
        $company = $this->company(['invoicing' => [true, true]]);

        $this->assertSame(
            ['accounting'],
            modules()->missingRequirements($company->getKey(), 'invoicing'),
            'Invoicing cannot be switched on while Accounting is unavailable.'
        );
    }

    public function test_a_new_company_is_provisioned_with_core_only(): void
    {
        $company = Company::factory()->create();
        modules()->seedDefaults($company->getKey());

        $this->assertTrue(modules()->enabledFor($company->getKey(), 'core'));

        foreach (array_diff(Modules::names(), ['core']) as $module) {
            $this->assertFalse(
                modules()->licensedFor($company->getKey(), $module),
                "A new company must not start with [{$module}] licensed."
            );
        }
    }

    public function test_revoking_a_licence_keeps_the_companys_own_choice(): void
    {
        $company = $this->company(['payroll' => [true, true]]);
        $id = $company->getKey();

        CompanyModule::where('company_id', $id)->where('module', 'payroll')->update(['licensed' => false]);
        modules()->flush();

        $this->assertFalse(modules()->enabledFor($id, 'payroll'), 'Unavailable while unlicensed.');
        $this->assertTrue(
            CompanyModule::where('company_id', $id)->where('module', 'payroll')->value('enabled'),
            'The enabled flag is the company\'s setting and must survive a revoke, so a '
            .'re-grant restores what they had.'
        );

        CompanyModule::where('company_id', $id)->where('module', 'payroll')->update(['licensed' => true]);
        modules()->flush();

        $this->assertTrue(modules()->enabledFor($id, 'payroll'), 'Re-granting restores it without a second toggle.');
    }

    public function test_the_upgrade_backfills_existing_companies_to_everything_on(): void
    {
        // The one behaviour that only happens once, on the release that adds this
        // feature: no existing customer loses a module on upgrade day. Exercised
        // by re-running the migration over companies that predate the table.
        $existing = Company::factory()->count(2)->create();

        DB::getSchemaBuilder()->drop('company_modules');

        $migration = require database_path('migrations/landlord/2026_07_29_090000_create_company_modules_table.php');
        $migration->up();

        modules()->flush();

        foreach ($existing as $company) {
            foreach (Modules::names() as $module) {
                $this->assertTrue(
                    modules()->enabledFor($company->getKey(), $module),
                    "Company [{$company->getKey()}] lost [{$module}] on upgrade."
                );
            }
        }
    }

    public function test_module_rows_are_removed_with_their_company(): void
    {
        $company = $this->company(['accounting' => [true, true]]);
        $id = $company->getKey();

        $company->delete();

        $this->assertSame(0, CompanyModule::where('company_id', $id)->count());
    }
}
