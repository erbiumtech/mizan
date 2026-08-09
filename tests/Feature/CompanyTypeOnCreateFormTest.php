<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Platform\Resources\Companies\Pages\CreateCompany;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Payroll\Models\SalarySlab;
use App\Support\Modules;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Choosing the kind of tenant when creating one.
 *
 * The provisioner has accepted a type since personal accounts were added, and
 * the create form never sent one — so every company made through the Platform
 * panel came out a business whatever was intended, and the only way to get a
 * personal account was to call the provisioner by hand.
 *
 * Nothing caught that, because the provisioner's own tests call it directly and
 * pass the type themselves. What was missing was a test of the path a person
 * actually uses, which is what this is.
 */
class CompanyTypeOnCreateFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['multitenancy.tenant_database_connection_name' => 'tenant']);
        $this->seed(PermissionSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'status' => 1]));
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

    private function create(string $name, ?string $type): Company
    {
        $admin = User::factory()->create(['status' => 1]);

        Livewire::test(CreateCompany::class)
            ->fillForm(array_filter([
                'name' => $name,
                'admin_user_id' => $admin->getKey(),
                'type' => $type,
            ], fn ($v) => $v !== null))
            ->call('create')
            ->assertHasNoFormErrors();

        return Company::where('name', $name)->firstOrFail();
    }

    public function test_the_form_offers_both_kinds(): void
    {
        $options = collect(Livewire::test(CreateCompany::class)->instance()->form->getFlatComponents())
            ->first(fn ($c): bool => method_exists($c, 'getName') && $c->getName() === 'type')
            ?->getOptions();

        $this->assertSame(
            Company::TYPE_LABELS,
            $options,
            'The create form does not offer a type, so every company comes out a business.',
        );

        // From the model, not spelled again here. The form and the list each
        // wrote their own pair once, and the form said "Business" while every
        // other screen said "Company".
        $this->assertSame(['business', 'personal'], array_keys(Company::TYPE_LABELS));
    }

    public function test_choosing_personal_actually_provisions_a_personal_account(): void
    {
        // The bug this exists for: the form could show the field and the page
        // could still drop it on the way to the provisioner.
        $company = $this->create('Ali Household', Company::TYPE_PERSONAL);

        $this->assertTrue($company->isPersonal());
        $this->assertSame('Personal Account', $company->typeLabel());
    }

    public function test_a_personal_account_is_seeded_from_the_household_chart(): void
    {
        $company = $this->create('Ali Household', Company::TYPE_PERSONAL);
        $company->makeCurrent();

        // 5350 Domestic Staff Wages exists only in the personal chart. Asserting
        // a code rather than a count, because both charts have roughly forty
        // accounts and a count would pass on the wrong one.
        $this->assertTrue(
            \App\Modules\Accounting\Models\Account::where('code', '5350')->exists(),
            'No Domestic Staff Wages account — the business chart was seeded instead.',
        );

        // And no payroll: a household does not run payslips.
        $this->assertSame(0, SalarySlab::count());
    }

    public function test_a_business_is_still_the_default_and_gets_the_trading_chart(): void
    {
        $company = $this->create('Acme Trading', null);
        $company->makeCurrent();

        $this->assertFalse($company->isPersonal());
        $this->assertFalse(
            \App\Modules\Accounting\Models\Account::where('code', '5350')->exists(),
            'A business was given the household chart.',
        );
        $this->assertGreaterThan(0, SalarySlab::count(), 'A business needs slabs to tax anybody.');
    }

    public function test_the_two_kinds_start_with_different_modules(): void
    {
        $personal = $this->create('Ali Household', Company::TYPE_PERSONAL);
        $business = $this->create('Acme Trading', Company::TYPE_BUSINESS);

        modules()->flush();

        $this->assertTrue(modules()->licensedFor($personal->getKey(), 'personal_finance'));
        $this->assertFalse(
            modules()->licensedFor($personal->getKey(), 'payroll'),
            'A personal account was licensed payroll, which it has no use for.',
        );

        // The business follows the registry defaults, not PERSONAL_DEFAULTS.
        $this->assertFalse(modules()->licensedFor($business->getKey(), 'personal_finance'));
    }

    public function test_the_type_cannot_be_changed_afterwards(): void
    {
        // It decides the chart of accounts, the spending categories and the
        // starting modules, all seeded once. Switching later would leave a
        // household chart labelled as a company, or a company with no salary
        // slabs. Asserted through the edit page, which is where somebody would
        // actually try it.
        $company = $this->create('Ali Household', Company::TYPE_PERSONAL);

        $field = collect(
            Livewire::test(\App\Modules\Core\Filament\Platform\Resources\Companies\Pages\EditCompany::class, [
                'record' => $company->getRouteKey(),
            ])->instance()->form->getFlatComponents()
        )->first(fn ($c): bool => method_exists($c, 'getName') && $c->getName() === 'type');

        $this->assertNotNull($field, 'The edit form does not show the type at all.');
        $this->assertTrue($field->isDisabled(), 'The type is editable after creation.');
    }

    public function test_personal_defaults_are_what_the_registry_says(): void
    {
        // Guards the list itself: a module added to PERSONAL_DEFAULTS that does
        // not exist would license nothing and fail silently.
        foreach (Modules::PERSONAL_DEFAULTS as $module) {
            $this->assertContains($module, Modules::names(), "PERSONAL_DEFAULTS names [{$module}], which is not a module.");
        }
    }
}
