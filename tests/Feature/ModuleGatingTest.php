<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\User;
use App\Support\ModuleMap;
use App\Support\Modules;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Behavioural coverage of the phase 1 gate, class by class.
 *
 * Deliberately not "does this class use BelongsToModule": a class that defines
 * its own canAccess() — which most pages and every widget here do — silently
 * shadows the trait, so asserting the trait is present would pass while the
 * module stayed reachable. These tests ask the question the panel asks.
 *
 * The deny direction is the invariant and is checked for every class. The allow
 * direction is checked per module rather than per class, because several pages
 * carry their own permission requirements on top of the module.
 */
class ModuleGatingTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    /**
     * Core is always available (it holds the Modules page, Users and Roles), so
     * there is no "disabled" state to assert for it.
     */
    private function gatedModules(): array
    {
        return array_values(array_filter(Modules::names(), fn (string $m) => ! Modules::isLocked($m)));
    }

    private function actAsSuperAdminOf(Company $company): User
    {
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        $user = User::factory()->create(['is_super_admin' => true]);
        $company->users()->attach($user->getKey());
        $user->assignRole('Administrator');

        $this->actingAs($user);
        $this->setCurrentTenant($company);

        return $user;
    }

    private function setModule(Company $company, string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $company->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    /**
     * @return array<int, class-string>
     */
    private function classesOf(string $module): array
    {
        return [
            ...ModuleMap::resources($module),
            ...ModuleMap::pages($module),
            ...ModuleMap::widgets($module),
        ];
    }

    public function test_a_disabled_module_denies_every_one_of_its_resources_pages_and_widgets(): void
    {
        $company = Company::factory()->create();
        $this->actAsSuperAdminOf($company);

        $reachable = [];

        foreach ($this->gatedModules() as $module) {
            $this->setModule($company, $module, false);

            foreach ($this->classesOf($module) as $class) {
                if ($this->isAccessible($class)) {
                    $reachable[] = "[{$module}] {$class}";
                }
            }

            // Back on, so one module's off-state cannot mask another's.
            $this->setModule($company, $module, true);
        }

        $this->assertSame([], $reachable, implode("\n", [
            'These are still reachable with their module switched off — so they stay in the',
            'sidebar, in global search and in the ⌘K palette for a company that has not',
            'bought them. A class defining its own canAccess()/canView() shadows',
            'BelongsToModule; it has to call static::moduleIsAvailable() itself.',
            '',
            ...$reachable,
        ]));
    }

    public function test_an_enabled_module_lets_its_own_surfaces_through(): void
    {
        // Proves the gate is not simply stuck shut. Per module rather than per
        // class because several pages require a specific permission on top.
        $company = Company::factory()->create();
        $this->actAsSuperAdminOf($company);

        foreach ($this->gatedModules() as $module) {
            $this->setModule($company, $module, true);

            $accessible = array_filter($this->classesOf($module), fn (string $c) => $this->isAccessible($c));

            $this->assertNotEmpty(
                $accessible,
                "Module [{$module}] is licensed and enabled but none of its surfaces are reachable."
            );
        }
    }

    public function test_a_licensed_but_switched_off_module_is_still_denied(): void
    {
        $company = Company::factory()->create();
        $this->actAsSuperAdminOf($company);

        CompanyModule::updateOrCreate(
            ['company_id' => $company->getKey(), 'module' => 'accounting'],
            ['licensed' => true, 'enabled' => false],
        );
        modules()->flush();

        $this->assertFalse(\App\Modules\Accounting\Filament\Resources\Accounts\AccountResource::canAccess());
        $this->assertFalse(\App\Modules\Accounting\Filament\Pages\TrialBalance::canAccess());
        $this->assertFalse(\App\Modules\Accounting\Filament\Widgets\CashFlowChart::canView());
    }

    public function test_core_surfaces_survive_every_other_module_being_off(): void
    {
        $company = Company::factory()->create();
        $this->actAsSuperAdminOf($company);

        foreach ($this->gatedModules() as $module) {
            $this->setModule($company, $module, false);
        }

        // Users and Roles must stay reachable or a company cannot administer
        // itself out of the state it just put itself in.
        $this->assertTrue(\App\Filament\Resources\Users\UserResource::canAccess());
        $this->assertTrue(\App\Filament\Resources\Roles\RoleResource::canAccess());
    }

    public function test_global_search_and_the_command_palette_follow_the_same_gate(): void
    {
        // Both consult canAccess(), so this is really a regression guard against
        // someone "optimising" one of them into a separate check.
        $company = Company::factory()->create();
        $this->actAsSuperAdminOf($company);

        $this->setModule($company, 'accounting', false);

        $this->assertFalse(\App\Modules\Accounting\Filament\Resources\Accounts\AccountResource::canGloballySearch());

        $this->setModule($company, 'accounting', true);

        $this->assertTrue(\App\Modules\Accounting\Filament\Resources\Accounts\AccountResource::canGloballySearch());
    }

    public function test_every_gated_class_reports_the_module_that_owns_it(): void
    {
        foreach ($this->gatedModules() as $module) {
            foreach ($this->classesOf($module) as $class) {
                $this->assertSame(
                    $module,
                    $class::module(),
                    "{$class} does not report [{$module}] as its module."
                );
            }
        }
    }

    private function isAccessible(string $class): bool
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        if (is_subclass_of($class, \Filament\Widgets\Widget::class)) {
            return $class::canView();
        }

        return $class::canAccess();
    }
}
