<?php

namespace Tests\Feature;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\EmployeeSettingResource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Filament\RelationManagers\EmployeeSettingComponentsRelationManager;
use App\Modules\Payroll\Models\EmployeeSettingComponent;
use App\Modules\Payroll\Models\PayComponent;
use App\Support\ResourceContributions;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Payroll reaches into Employees; Employees does not reach back.
 *
 * `payroll` requires `employees`, so an EmployeeSetting that named a PayComponent made the pair a cycle —
 * and this was the **linchpin** of the seven-module component left after phase 9: accounting, attendance and
 * leave were each held in it only by a three-cycle running back through this one edge. Deleting it freed
 * four modules.
 *
 * Both references were written in fully-qualified form so no import would appear, and the relation manager's
 * own docblock said that was deliberate. That is the manoeuvre docs/module-packaging-plan.md calls the cycle
 * being avoided in the lint rather than in the code; phase 0's scan sees through it. The fix is phase 7's:
 * Employees offers a slot, `PayrollServiceProvider` fills it, and Employees names nothing.
 *
 * What this file protects is that the reversal cost no behaviour. A relation registered at boot is invisible
 * to every reader of `EmployeeSetting.php`, so the obvious failure — somebody deletes the registration and
 * the tab silently disappears — has to be caught here. Modelled on `ProjectsContributionTest`, which guards
 * the same mechanism for phase 7.
 */
class PayrollContributionTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'payrollcontrib@test.local'));
        $this->setCurrentTenant();
    }

    private function setting(): EmployeeSetting
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-1',
            'name' => 'Ali Raza',
            'gender' => 'Male',
            'is_active' => true,
        ]);

        $year = FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30'],
        );

        return EmployeeSetting::create([
            'employee_id' => $employee->getKey(),
            'fiscal_year_id' => $year->getKey(),
            'start_date' => '2026-07-01',
            'basic_wage' => 100000,
        ]);
    }

    private function payComponent(string $label, string $kind = PayComponent::KIND_EARNING): PayComponent
    {
        return PayComponent::create([
            'code' => strtolower(str_replace(' ', '_', $label)),
            'label' => $label,
            'kind' => $kind,
            // Required: a data-driven component with no account produces net pay with no debit behind it.
            'account_key' => 'bonus_overtime',
        ]);
    }

    /**
     * The relation exists, is the right kind, and points at the component model.
     *
     * Asserted through `$setting->components()` — the method call — because that is how every caller reaches
     * it, and it is the form that does *not* obviously work: `__call()` consults the relation resolvers
     * before falling through to the query builder, which is the fact the whole design rests on.
     */
    public function test_payroll_registers_the_components_relation(): void
    {
        $setting = $this->setting();

        $this->assertInstanceOf(HasMany::class, $setting->components());
        $this->assertInstanceOf(EmployeeSettingComponent::class, $setting->components()->getRelated());
    }

    /**
     * The foreign key is the one the table actually has.
     *
     * `hasMany()` infers the key from the *calling method's* name, and inside a closure that name is
     * `{closure}` — the phase 8C failure, which produced a query for a column called
     * `..._payroll_{closure}_id` and thirteen red tests. A relation that resolves but queries the wrong
     * column is the failure mode this asserts against, so it reads a row back rather than only checking the
     * type.
     */
    public function test_the_contributed_relation_queries_the_right_column(): void
    {
        $setting = $this->setting();
        $component = $this->payComponent('Fuel allowance');

        EmployeeSettingComponent::create([
            'employee_setting_id' => $setting->getKey(),
            'pay_component_id' => $component->getKey(),
            'amount' => 7500,
        ]);

        $this->assertSame(
            'employee_setting_id',
            $setting->components()->getForeignKeyName(),
            'the foreign key was inferred from a closure name rather than named',
        );

        $this->assertSame([7500.0], $setting->components()->pluck('amount')->map('floatval')->all());
    }

    /**
     * The tab is on the Employee Settings screen, and Employees did not put it there.
     *
     * Both halves matter. The first is the behaviour; the second is the architecture, and asserting only the
     * first would let somebody "fix" a future problem by naming the class in EmployeeSettingResource again —
     * restoring the cycle while every test stayed green.
     */
    public function test_the_components_tab_is_contributed_rather_than_declared(): void
    {
        $this->assertContains(
            EmployeeSettingComponentsRelationManager::class,
            EmployeeSettingResource::getRelations(),
            'the added-components tab is missing from the Employee Settings screen',
        );

        $this->assertContains(
            EmployeeSettingComponentsRelationManager::class,
            ResourceContributions::relationManagersFor(EmployeeSettingResource::class),
            'the added-components tab is not registered as a contribution',
        );

        // Neither the resource nor the model names Payroll — the check the module lint performs, made
        // legible here so a reviewer sees why the indirection exists. Both files are asserted because the
        // edge was two references in two different files, and fixing one would have left the cycle standing.
        foreach ([EmployeeSettingResource::class, EmployeeSetting::class] as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());

            $this->assertStringNotContainsString(
                'Modules\\Payroll\\',
                $source,
                $class.' names the Payroll module again, which restores the cycle',
            );
        }
    }

    /** The tab renders with a component in it, which is the only state its columns run in. */
    public function test_the_contributed_tab_renders(): void
    {
        $setting = $this->setting();
        $component = $this->payComponent('Fuel allowance');

        EmployeeSettingComponent::create([
            'employee_setting_id' => $setting->getKey(),
            'pay_component_id' => $component->getKey(),
            'amount' => 7500,
        ]);

        \Livewire\Livewire::test(EmployeeSettingComponentsRelationManager::class, [
            'ownerRecord' => $setting,
            'pageClass' => \App\Modules\Employees\Filament\Resources\EmployeeSettings\Pages\EditEmployeeSetting::class,
        ])
            ->assertSuccessful()
            ->assertSee('Fuel allowance');
    }
}
