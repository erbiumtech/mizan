<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Platform\Resources\Companies\Pages\CreateCompany;
use App\Modules\Core\Filament\Platform\Resources\Companies\Pages\EditCompany;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Multitenancy\CompanyProvisioner;
use App\Support\CompanyProfiles;
use App\Support\Modules;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PersonalBaselineSeeder;
use Database\Seeders\PersonalChartOfAccountsSeeder;
use Database\Seeders\PersonalTransactionTypeSeeder;
use Database\Seeders\SalarySlabSeeder;
use Database\Seeders\TenantBaselineSeeder;
use Database\Seeders\TransactionTypeSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Company profiles: what kind of business a tenant is, and what follows.
 *
 * Two halves. The first is a set of invariants over config/company_profiles.php
 * that hold without touching a database — every one of them guards a failure
 * that is silent in production (a licence that can never be switched on, a
 * module nobody can buy, reference data that never arrives). The second
 * provisions real tenants and asserts the behaviour end to end.
 *
 * The compatibility case matters as much as the feature: a company with no
 * profile must be licensed and seeded exactly as it was before profiles existed,
 * because every company that exists today is one.
 */
class CompanyProfileTest extends TestCase
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

    // ---------------------------------------------------------------- registry

    public function test_the_registry_is_not_empty_and_every_entry_is_complete(): void
    {
        // Guards the guard: every assertion below iterates the registry, so an
        // empty or renamed config file would pass all of them over nothing.
        $this->assertNotEmpty(CompanyProfiles::names());

        foreach (CompanyProfiles::all() as $profile => $definition) {
            foreach (['label', 'description', 'type', 'modules', 'seeders'] as $key) {
                $this->assertArrayHasKey($key, $definition, "Profile [{$profile}] has no [{$key}].");
            }

            $this->assertContains(
                $definition['type'],
                array_keys(Company::TYPE_LABELS),
                "Profile [{$profile}] claims type [{$definition['type']}], which is not a company type.",
            );

            $this->assertNotEmpty($definition['modules'], "Profile [{$profile}] licenses nothing.");
            $this->assertNotEmpty($definition['seeders'], "Profile [{$profile}] seeds nothing.");
        }
    }

    public function test_every_profile_names_only_real_modules(): void
    {
        // seedDefaults() matches on the module key with in_array, so a typo
        // licenses nothing at all and says nothing about it.
        foreach (CompanyProfiles::all() as $profile => $definition) {
            foreach ($definition['modules'] as $module) {
                $this->assertContains(
                    $module,
                    Modules::names(),
                    "Profile [{$profile}] names module [{$module}], which does not exist.",
                );
            }
        }
    }

    public function test_every_profile_is_closed_under_module_requirements(): void
    {
        // The failure this prevents: Modules::enabledFor() recurses into
        // requirements, so a module licensed without them is licensed, shows a
        // toggle on the company's own Modules page, and can never be switched
        // on. It reads as a broken toggle, not as a bad preset.
        foreach (CompanyProfiles::all() as $profile => $definition) {
            foreach ($definition['modules'] as $module) {
                foreach (Modules::requirements($module) as $required) {
                    if (Modules::isLocked($required)) {
                        continue;
                    }

                    $this->assertContains(
                        $required,
                        $definition['modules'],
                        "Profile [{$profile}] licenses [{$module}], which requires [{$required}] — "
                        .'so that module can never be switched on.',
                    );
                }
            }
        }
    }

    public function test_every_module_is_recommended_by_at_least_one_profile(): void
    {
        // The forcing function that co-locating profiles on each module entry
        // would have given, per docs/company-profiles-plan.md §4. A module in no
        // profile is never licensed for any new company, and the way you find
        // out is a customer asking for it.
        $covered = [];

        foreach (CompanyProfiles::all() as $definition) {
            foreach ($definition['modules'] as $module) {
                $covered[$module] = true;
            }
        }

        $orphans = array_values(array_filter(
            Modules::names(),
            fn (string $module): bool => ! Modules::isLocked($module) && ! isset($covered[$module]),
        ));

        $this->assertSame([], $orphans, implode("\n", [
            'These modules belong to no company profile, so no new company is ever licensed them:',
            ...$orphans,
            'Add each to a profile in config/company_profiles.php, or explain why it is unsellable.',
        ]));
    }

    public function test_every_seeder_a_profile_names_exists(): void
    {
        foreach (CompanyProfiles::all() as $profile => $definition) {
            foreach ($definition['seeders'] as $seeder) {
                $this->assertTrue(
                    class_exists($seeder),
                    "Profile [{$profile}] names seeder [{$seeder}], which does not exist.",
                );

                $this->assertTrue(
                    is_subclass_of($seeder, Seeder::class),
                    "Profile [{$profile}] names [{$seeder}], which is not a seeder.",
                );
            }
        }
    }

    public function test_a_profile_seeds_salary_slabs_exactly_when_it_licenses_payroll(): void
    {
        // Both directions are real bugs. Payroll with no slabs cannot tax
        // anybody; slabs with no Payroll are reference data for a screen the
        // company cannot open. Neither throws.
        foreach (CompanyProfiles::all() as $profile => $definition) {
            $licensesPayroll = in_array('payroll', $definition['modules'], true);
            $seedsSlabs = in_array(SalarySlabSeeder::class, $definition['seeders'], true);

            $this->assertSame(
                $licensesPayroll,
                $seedsSlabs,
                "Profile [{$profile}] licenses payroll=".var_export($licensesPayroll, true)
                .' but seeds salary slabs='.var_export($seedsSlabs, true).'.',
            );
        }
    }

    public function test_the_chart_and_its_transaction_types_match_the_profile_type(): void
    {
        // PersonalBaselineSeeder says why: the transaction types are keyed to
        // their own chart's account codes, so a mismatched pair produces
        // spending categories pointing at accounts that mean something else.
        foreach (CompanyProfiles::all() as $profile => $definition) {
            $personal = $definition['type'] === Company::TYPE_PERSONAL;
            $seeders = $definition['seeders'];

            $this->assertSame(
                $personal,
                in_array(PersonalChartOfAccountsSeeder::class, $seeders, true),
                "Profile [{$profile}] has the wrong chart of accounts for its type.",
            );

            $this->assertSame(
                $personal,
                in_array(PersonalTransactionTypeSeeder::class, $seeders, true),
                "Profile [{$profile}] has transaction types that do not match its chart.",
            );

            $this->assertSame(
                ! $personal,
                in_array(ChartOfAccountsSeeder::class, $seeders, true),
                "Profile [{$profile}] has the wrong chart of accounts for its type.",
            );

            $this->assertSame(
                ! $personal,
                in_array(TransactionTypeSeeder::class, $seeders, true),
                "Profile [{$profile}] has transaction types that do not match its chart.",
            );
        }
    }

    public function test_the_personal_profile_is_the_personal_defaults_it_replaces(): void
    {
        // The personal profile has to be a rename, not a redesign: every
        // personal account provisioned before it existed got PERSONAL_DEFAULTS
        // and PersonalBaselineSeeder, and both paths still run.
        $this->assertSame(
            Modules::PERSONAL_DEFAULTS,
            CompanyProfiles::modules('personal'),
            'The personal profile no longer matches PERSONAL_DEFAULTS, so an old personal '
            .'account and a new one would start with different modules.',
        );

        $this->assertSame(
            PersonalBaselineSeeder::seeders(),
            CompanyProfiles::seeders('personal'),
            'The personal profile no longer seeds what PersonalBaselineSeeder seeds.',
        );
    }

    public function test_no_profile_falls_back_to_the_pre_profile_behaviour(): void
    {
        // The compatibility guarantee, asserted rather than assumed: every
        // company that exists today has a null profile.
        $this->assertNull(CompanyProfiles::modules(null));

        $this->assertSame(
            TenantBaselineSeeder::seeders(),
            CompanyProfiles::seeders(null, Company::TYPE_BUSINESS),
        );

        $this->assertSame(
            PersonalBaselineSeeder::seeders(),
            CompanyProfiles::seeders(null, Company::TYPE_PERSONAL),
        );
    }

    public function test_profiles_are_offered_only_for_their_own_company_type(): void
    {
        $business = CompanyProfiles::optionsForType(Company::TYPE_BUSINESS);
        $personal = CompanyProfiles::optionsForType(Company::TYPE_PERSONAL);

        $this->assertNotEmpty($business);
        $this->assertNotEmpty($personal);
        $this->assertSame([], array_intersect_key($business, $personal));

        // A personal account has exactly one shape, so it can be preselected.
        // A business has six, and guessing would be worse than asking.
        $this->assertSame('personal', CompanyProfiles::defaultForType(Company::TYPE_PERSONAL));
        $this->assertNull(CompanyProfiles::defaultForType(Company::TYPE_BUSINESS));
    }

    // ------------------------------------------------------------ provisioning

    public function test_provisioning_with_a_profile_licenses_exactly_that_profile(): void
    {
        $company = $this->provision('Acme Trading', Company::TYPE_BUSINESS, 'trading');

        modules()->flush();

        foreach (CompanyProfiles::modules('trading') as $module) {
            $this->assertTrue(
                modules()->licensedFor($company->getKey(), $module),
                "Trading did not license [{$module}].",
            );
        }

        // And nothing else. Projects is the interesting one: it is a perfectly
        // good module that this kind of company has no use for.
        $this->assertFalse(modules()->licensedFor($company->getKey(), 'projects'));
        $this->assertFalse(modules()->licensedFor($company->getKey(), 'mpr'));
    }

    public function test_a_licensed_profile_module_is_actually_usable(): void
    {
        // Licensed is not the same as available. This is the assertion that
        // would have caught a profile that is not closed under requirements —
        // enabledFor() is what every gate in the application actually asks.
        $company = $this->provision('Big Staffing', Company::TYPE_BUSINESS, 'staffing');

        modules()->flush();

        foreach (CompanyProfiles::all() as $profile => $definition) {
            if ($profile !== 'staffing') {
                continue;
            }

            foreach ($definition['modules'] as $module) {
                $this->assertTrue(
                    modules()->enabledFor($company->getKey(), $module),
                    "[{$module}] is licensed to a staffing company but cannot be switched on.",
                );
            }
        }
    }

    public function test_a_profile_without_payroll_gets_no_salary_slabs(): void
    {
        $company = $this->provision('Books Only', Company::TYPE_BUSINESS, 'bookkeeping');
        $company->makeCurrent();

        $this->assertSame(
            0,
            \App\Modules\Payroll\Models\SalarySlab::count(),
            'A bookkeeping-only company was seeded salary slabs it has no payroll for.',
        );

        // But it is still a business, so it gets the business chart — the two
        // decisions are separate and this is where that shows.
        $this->assertTrue(
            \App\Modules\Accounting\Models\Account::query()->exists(),
            'A bookkeeping company was seeded no chart of accounts at all.',
        );

        $this->assertFalse(
            \App\Modules\Accounting\Models\Account::where('code', '5350')->exists(),
            'A business was given the household chart.',
        );
    }

    public function test_a_business_profile_with_payroll_still_gets_its_slabs(): void
    {
        $company = $this->provision('Acme Services', Company::TYPE_BUSINESS, 'services');
        $company->makeCurrent();

        $this->assertGreaterThan(
            0,
            \App\Modules\Payroll\Models\SalarySlab::count(),
            'A services company needs slabs to tax anybody.',
        );
    }

    public function test_provisioning_without_a_profile_is_unchanged(): void
    {
        $business = $this->provision('No Profile Ltd', Company::TYPE_BUSINESS, null);

        modules()->flush();

        $this->assertNull($business->profile);

        // Registry defaults: Core alone.
        foreach (Modules::names() as $module) {
            $this->assertSame(
                (bool) config("modules.{$module}.licensed_by_default"),
                modules()->licensedFor($business->getKey(), $module),
                "An unprofiled company's licence for [{$module}] no longer matches the registry default.",
            );
        }

        $business->makeCurrent();

        // And the full business baseline, slabs included.
        $this->assertGreaterThan(0, \App\Modules\Payroll\Models\SalarySlab::count());
        $this->assertTrue(\App\Modules\Accounting\Models\Account::query()->exists());
    }

    public function test_a_profile_may_not_be_provisioned_against_the_wrong_type(): void
    {
        // The combination `type` exists to prevent: a business chart seeded into
        // a household. Refused before anything is written.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is for a company of type \[business\]/');

        $this->provision('Ali Household', Company::TYPE_PERSONAL, 'trading');
    }

    public function test_an_unknown_profile_is_refused_rather_than_ignored(): void
    {
        // Dropping it silently would license the registry defaults while the
        // caller believed it had asked for something else.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no company profile \[wholesale\]/');

        $this->provision('Wholesale Ltd', Company::TYPE_BUSINESS, 'wholesale');
    }

    public function test_a_refused_profile_leaves_no_company_behind(): void
    {
        try {
            $this->provision('Wholesale Ltd', Company::TYPE_BUSINESS, 'wholesale');
        } catch (RuntimeException) {
            // Expected — asserted above.
        }

        $this->assertSame(
            0,
            Company::where('name', 'Wholesale Ltd')->count(),
            'A rejected profile left an orphan company row.',
        );
    }

    // ------------------------------------------------------------------- forms

    public function test_the_create_form_offers_profiles_for_the_chosen_type(): void
    {
        $this->actingAsSuperAdmin();

        $field = collect(Livewire::test(CreateCompany::class)->instance()->form->getFlatComponents())
            ->first(fn ($c): bool => method_exists($c, 'getName') && $c->getName() === 'profile');

        $this->assertNotNull($field, 'The create form does not offer a profile at all.');

        // The type defaults to business, so the options must be the business
        // ones — offering "Personal Account" here would seed a household chart
        // into a company.
        $this->assertSame(
            CompanyProfiles::optionsForType(Company::TYPE_BUSINESS),
            $field->getOptions(),
        );
    }

    public function test_choosing_a_profile_on_the_form_actually_provisions_it(): void
    {
        // The bug the type field had for months: the form can show a field and
        // the page can still drop it on the way to the provisioner.
        $this->actingAsSuperAdmin();

        $admin = User::factory()->create(['status' => 1]);

        Livewire::test(CreateCompany::class)
            ->fillForm([
                'name' => 'Form Trading',
                'admin_user_id' => $admin->getKey(),
                'type' => Company::TYPE_BUSINESS,
                'profile' => 'trading',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $company = Company::where('name', 'Form Trading')->firstOrFail();

        modules()->flush();

        $this->assertSame('trading', $company->profile);
        $this->assertTrue(modules()->licensedFor($company->getKey(), 'inventory'));
        $this->assertFalse(modules()->licensedFor($company->getKey(), 'projects'));
    }

    public function test_the_profile_stays_editable_after_creation(): void
    {
        // Unlike the type. It decides the starting licences and the baseline,
        // but it also describes the company for the rest of its life, and
        // companies change shape.
        $this->actingAsSuperAdmin();

        $company = $this->provision('Acme Trading', Company::TYPE_BUSINESS, 'trading');

        $field = collect(
            Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
                ->instance()->form->getFlatComponents()
        )->first(fn ($c): bool => method_exists($c, 'getName') && $c->getName() === 'profile');

        $this->assertNotNull($field, 'The edit form does not show the profile.');
        $this->assertFalse($field->isDisabled(), 'The profile cannot be corrected after creation.');
    }

    // ----------------------------------------------------------- apply licences

    public function test_applying_a_profile_grants_what_is_missing(): void
    {
        $this->actingAsSuperAdmin();

        // Provisioned as bookkeeping, and now doing project work.
        $company = $this->provision('Grown Up Ltd', Company::TYPE_BUSINESS, 'bookkeeping');
        $company->update(['profile' => 'software_house']);

        modules()->flush();

        $this->assertFalse(modules()->licensedFor($company->getKey(), 'projects'));

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->callAction('applyProfileLicences');

        modules()->flush();

        foreach (CompanyProfiles::modules('software_house') as $module) {
            $this->assertTrue(
                modules()->licensedFor($company->getKey(), $module),
                "Applying the profile did not grant [{$module}].",
            );
        }
    }

    public function test_applying_a_profile_never_revokes_anything(): void
    {
        $this->actingAsSuperAdmin();

        // Inventory was sold to this company outside its profile. A button
        // labelled "apply" must not take back something somebody sold.
        $company = $this->provision('Odd Fit Ltd', Company::TYPE_BUSINESS, 'software_house');

        CompanyModule::updateOrCreate(
            ['company_id' => $company->getKey(), 'module' => 'inventory'],
            ['licensed' => true],
        );

        modules()->flush();

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->callAction('applyProfileLicences');

        modules()->flush();

        $this->assertTrue(
            modules()->licensedFor($company->getKey(), 'inventory'),
            'Applying the profile revoked a module the company had been sold.',
        );
    }

    public function test_applying_a_profile_does_not_switch_on_what_the_company_switched_off(): void
    {
        // The single most valuable assertion here. `enabled` is three-state so
        // that an explicit false — the company's own decision — survives licence
        // changes. seedDefaults() writes `enabled`; this path must not.
        $this->actingAsSuperAdmin();

        $company = $this->provision('Opted Out Ltd', Company::TYPE_BUSINESS, 'software_house');

        CompanyModule::updateOrCreate(
            ['company_id' => $company->getKey(), 'module' => 'mpr'],
            ['licensed' => true, 'enabled' => false],
        );

        modules()->flush();

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->callAction('applyProfileLicences');

        modules()->flush();

        $this->assertFalse(
            modules()->enabledFor($company->getKey(), 'mpr'),
            'Applying the profile switched a module back on that the company had switched off.',
        );

        $this->assertSame(
            0,
            (int) CompanyModule::where('company_id', $company->getKey())
                ->where('module', 'mpr')
                ->value('enabled'),
            'The company\'s own off-choice was overwritten rather than left alone.',
        );
    }

    public function test_applying_a_profile_twice_changes_nothing_the_second_time(): void
    {
        $this->actingAsSuperAdmin();

        $company = $this->provision('Twice Ltd', Company::TYPE_BUSINESS, 'trading');

        modules()->flush();

        $before = modules()->stateFor($company->getKey());

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->callAction('applyProfileLicences');

        modules()->flush();

        $this->assertSame($before, modules()->stateFor($company->getKey()));
    }

    // ------------------------------------------------------------------ helpers

    private function provision(string $name, string $type, ?string $profile): Company
    {
        return app(CompanyProvisioner::class)->provision(
            name: $name,
            creator: User::factory()->create(['status' => 1]),
            type: $type,
            profile: $profile,
        );
    }

    private function actingAsSuperAdmin(): Model
    {
        Filament::setCurrentPanel(Filament::getPanel('platform'));

        $user = User::factory()->create(['is_super_admin' => true, 'status' => 1]);

        $this->actingAs($user);

        return $user;
    }
}
