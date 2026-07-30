<?php

namespace Tests\Feature;

use App\Filament\Pages\Modules as ModulesPage;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The two admin surfaces: a super admin grants licences on the Company edit page,
 * and the company's own Administrator switches the licensed ones on and off.
 */
class ModuleAdminTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->company = Company::factory()->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        (new RoleSeeder)->run();
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        $this->company->users()->attach($user->getKey());
        $user->assignRole('Administrator');

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $this->company->users()->attach($user->getKey());

        return $user;
    }

    private function licence(string $module, bool $licensed, ?bool $enabled = null): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->company->getKey(), 'module' => $module],
            ['licensed' => $licensed, 'enabled' => $enabled],
        );

        modules()->flush();
    }

    // ---------------------------------------------------------------- activation

    public function test_the_activation_page_lists_only_licensed_modules(): void
    {
        // An unlicensed module is absent, not shown as a locked toggle: the company
        // cannot act on it, so a control that always fails is worse than no control.
        $this->licence('accounting', true);
        $this->licence('payroll', false);

        $this->actingAs($this->administrator());
        $this->setCurrentTenant($this->company);

        Livewire::test(ModulesPage::class)
            ->assertFormFieldExists('accounting')
            ->assertFormFieldDoesNotExist('payroll')
            ->assertFormFieldDoesNotExist('core');
    }

    public function test_core_never_appears_on_the_activation_page(): void
    {
        $this->actingAs($this->administrator());
        $this->setCurrentTenant($this->company);

        Livewire::test(ModulesPage::class)->assertFormFieldDoesNotExist('core');
    }

    public function test_an_administrator_can_switch_a_licensed_module_off(): void
    {
        $this->licence('mpr', true, true);

        $this->actingAs($this->administrator());
        $this->setCurrentTenant($this->company);

        Livewire::test(ModulesPage::class)
            ->fillForm(['mpr' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        modules()->flush();

        $this->assertFalse(modules()->enabledFor($this->company->getKey(), 'mpr'));
        $this->assertTrue(
            modules()->licensedFor($this->company->getKey(), 'mpr'),
            'Switching off must not touch the licence.'
        );
    }

    public function test_switching_a_module_off_switches_off_what_depends_on_it(): void
    {
        // Rather than leaving Invoicing half-working against a missing Accounting.
        $this->licence('accounting', true, true);
        $this->licence('invoicing', true, true);

        $this->actingAs($this->administrator());
        $this->setCurrentTenant($this->company);

        Livewire::test(ModulesPage::class)
            ->fillForm(['accounting' => false, 'invoicing' => true])
            ->call('save');

        modules()->flush();

        $this->assertFalse(modules()->enabledFor($this->company->getKey(), 'accounting'));
        $this->assertFalse(
            modules()->enabledFor($this->company->getKey(), 'invoicing'),
            'Invoicing issues journal entries, so it cannot stay on without Accounting.'
        );
    }

    public function test_switching_a_module_on_pulls_in_what_it_requires(): void
    {
        $this->licence('accounting', true, false);
        $this->licence('invoicing', true, false);

        $this->actingAs($this->administrator());
        $this->setCurrentTenant($this->company);

        Livewire::test(ModulesPage::class)
            ->fillForm(['accounting' => false, 'invoicing' => true])
            ->call('save');

        modules()->flush();

        $this->assertTrue(modules()->enabledFor($this->company->getKey(), 'invoicing'));
        $this->assertTrue(
            modules()->enabledFor($this->company->getKey(), 'accounting'),
            'Enabling Invoicing must pull Accounting in with it.'
        );
    }

    public function test_a_module_cannot_be_switched_on_when_its_requirement_is_unlicensed(): void
    {
        // The requirement cannot be pulled in, so the module that needs it cannot
        // go on either — and the page says so rather than saving a broken state.
        $this->licence('accounting', false);
        $this->licence('inventory', true, false);

        $this->actingAs($this->administrator());
        $this->setCurrentTenant($this->company);

        Livewire::test(ModulesPage::class)
            ->fillForm(['inventory' => true])
            ->call('save');

        modules()->flush();

        $this->assertFalse(modules()->enabledFor($this->company->getKey(), 'inventory'));
    }

    public function test_a_non_administrator_cannot_reach_the_activation_page(): void
    {
        $user = User::factory()->create();
        $this->company->users()->attach($user->getKey());
        $user->assignRole('Employee');

        $this->actingAs($user);
        $this->setCurrentTenant($this->company);

        $this->assertFalse(ModulesPage::canAccess());
    }

    public function test_switching_a_module_is_written_to_the_activity_log(): void
    {
        // "Who turned Payroll off and when" is where every such incident starts.
        $this->licence('mpr', true, true);

        $this->actingAs($this->administrator());
        $this->setCurrentTenant($this->company);

        Livewire::test(ModulesPage::class)
            ->fillForm(['mpr' => false])
            ->call('save');

        $this->assertDatabaseHas('activity_log', [
            'description' => 'Disabled the MPR module',
        ]);
    }

    // ----------------------------------------------------------------- licensing

    public function test_a_super_admin_grants_a_licence_from_the_company_page(): void
    {
        $this->licence('payroll', false);

        $this->actingAs($this->superAdmin());
        $this->setCurrentTenant($this->company);

        Livewire::test(EditCompany::class, ['record' => $this->company->getRouteKey()])
            ->fillForm(['modules' => ['payroll' => true]])
            ->call('save')
            ->assertHasNoFormErrors();

        modules()->flush();

        $this->assertTrue(modules()->licensedFor($this->company->getKey(), 'payroll'));
        $this->assertTrue(
            modules()->enabledFor($this->company->getKey(), 'payroll'),
            'A first grant should light the module up, not leave the company to enable what it just paid for.'
        );
    }

    public function test_revoking_a_licence_preserves_the_companys_own_choice(): void
    {
        // The three-state `enabled` column exists for this: an explicit false is
        // the company's decision and has to survive a revoke.
        $this->licence('payroll', true, false);

        $this->actingAs($this->superAdmin());
        $this->setCurrentTenant($this->company);

        Livewire::test(EditCompany::class, ['record' => $this->company->getRouteKey()])
            ->fillForm(['modules' => ['payroll' => false]])
            ->call('save');

        $this->assertFalse(
            CompanyModule::where('company_id', $this->company->getKey())->where('module', 'payroll')->value('enabled'),
        );

        // Re-grant: their "off" is restored rather than being reset to on.
        Livewire::test(EditCompany::class, ['record' => $this->company->getRouteKey()])
            ->fillForm(['modules' => ['payroll' => true]])
            ->call('save');

        modules()->flush();

        $this->assertTrue(modules()->licensedFor($this->company->getKey(), 'payroll'));
        $this->assertFalse(
            modules()->enabledFor($this->company->getKey(), 'payroll'),
            'A re-grant must restore what the company had, not override it.'
        );
    }

    public function test_a_licence_change_is_written_to_the_activity_log(): void
    {
        $this->licence('inventory', false);

        $this->actingAs($this->superAdmin());
        $this->setCurrentTenant($this->company);

        Livewire::test(EditCompany::class, ['record' => $this->company->getRouteKey()])
            ->fillForm(['modules' => ['inventory' => true]])
            ->call('save');

        $this->assertDatabaseHas('activity_log', [
            'description' => 'Granted the Inventory module licence',
        ]);
    }

    public function test_core_is_not_offered_as_a_licence(): void
    {
        $this->actingAs($this->superAdmin());
        $this->setCurrentTenant($this->company);

        Livewire::test(EditCompany::class, ['record' => $this->company->getRouteKey()])
            ->assertFormFieldDoesNotExist('modules.core');
    }
}
