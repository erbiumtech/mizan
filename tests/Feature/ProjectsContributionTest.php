<?php

namespace Tests\Feature;

use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Filament\RelationManagers\EmployeeProjectsRelationManager;
use App\Modules\Projects\Models\Project;
use App\Support\ResourceContributions;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Projects reaches into Employees; Employees does not reach back.
 *
 * `projects` requires `employees`, so an Employee that named a Project made the pair a cycle — and a
 * cycle is the one coupling composer cannot express, so neither module could ever be packaged. The fix
 * was to reverse the direction rather than to hide it: the three project relations and the Projects tab
 * on the Employee screen are both registered by `ProjectsServiceProvider`, and Employees names nothing.
 *
 * What this file protects is that the reversal did not quietly cost any behaviour. A relation registered
 * at boot is invisible to every reader of `Employee.php`, so the obvious failure — somebody deletes the
 * registration, or renames a relation, and the tab silently disappears — has to be caught here.
 *
 * See docs/module-packaging-plan.md phase 7, and App\Support\ResourceContributions for why a string class
 * name on the resource was not the answer.
 */
class ProjectsContributionTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'contrib@test.local'));
        $this->setCurrentTenant();
    }

    private function employee(string $code = 'EMP-1'): Employee
    {
        return Employee::create([
            'employee_id' => $code,
            'name' => 'Ali Raza',
            'gender' => 'Male',
            'is_active' => true,
        ]);
    }

    private function project(string $code, string $name): Project
    {
        return Project::create(['code' => $code, 'name' => $name]);
    }

    /**
     * The relations exist, are the right kind, and point at Project.
     *
     * Asserted through `$employee->relation()` — the method call — because that is how every caller in
     * this application reaches them, and it is the form that does *not* obviously work: `__call()`
     * consults the relation resolvers before falling through to the query builder
     * (`vendor/laravel/framework/.../Model.php:2833`), which is the fact the whole design rests on.
     */
    public function test_projects_registers_the_employee_relations(): void
    {
        $employee = $this->employee();

        $this->assertInstanceOf(BelongsToMany::class, $employee->projects());
        $this->assertInstanceOf(HasMany::class, $employee->managedProjects());
        $this->assertInstanceOf(HasMany::class, $employee->secondaryProjects());

        foreach (['projects', 'managedProjects', 'secondaryProjects'] as $relation) {
            $this->assertInstanceOf(
                Project::class,
                $employee->{$relation}()->getRelated(),
                "[{$relation}] does not relate to a Project",
            );
        }
    }

    /** The pivot the assignment relation carries — a stint, not just a link. */
    public function test_the_assignment_relation_keeps_its_pivot(): void
    {
        $employee = $this->employee();
        $project = $this->project('P-1', 'Migration');

        $employee->projects()->attach($project, [
            'role' => 'Developer',
            'allocation_pct' => 50,
            'from_date' => '2026-01-01',
        ]);

        $assigned = $employee->projects()->first();

        $this->assertSame('Developer', $assigned->pivot->role);
        $this->assertSame(50, (int) $assigned->pivot->allocation_pct);
        $this->assertNotNull($assigned->pivot->from_date);
    }

    /** The managed relations read the columns they are named for, which is easy to transpose. */
    public function test_the_manager_relations_read_the_right_columns(): void
    {
        $employee = $this->employee();

        $primary = $this->project('P-1', 'Primary');
        $primary->update(['manager_employee_id' => $employee->getKey()]);

        $secondary = $this->project('P-2', 'Secondary');
        $secondary->update(['secondary_employee_id' => $employee->getKey()]);

        $this->assertSame([$primary->getKey()], $employee->managedProjects()->pluck('id')->all());
        $this->assertSame([$secondary->getKey()], $employee->secondaryProjects()->pluck('id')->all());
    }

    /**
     * The Projects tab is on the Employee screen, and Employees did not put it there.
     *
     * Both halves matter. The first is the behaviour; the second is the architecture, and asserting only
     * the first would let somebody "fix" a future problem by naming the class in EmployeeResource again —
     * restoring the cycle while every test stayed green.
     */
    public function test_the_projects_tab_is_contributed_rather_than_declared(): void
    {
        $this->assertContains(
            EmployeeProjectsRelationManager::class,
            EmployeeResource::getRelations(),
            'the Projects tab is missing from the Employee screen',
        );

        $this->assertContains(
            EmployeeProjectsRelationManager::class,
            ResourceContributions::relationManagersFor(EmployeeResource::class),
            'the Projects tab is not registered as a contribution',
        );

        // And the source file does not mention Projects at all — the check the module lint performs, made
        // legible here so a reviewer sees why the indirection exists.
        $source = file_get_contents((new \ReflectionClass(EmployeeResource::class))->getFileName());

        $this->assertStringNotContainsString(
            'Modules\\Projects',
            $source,
            'EmployeeResource names the Projects module again, which restores the cycle',
        );
    }

    /** The tab renders with a project in it, which is the only state its columns run in. */
    public function test_the_contributed_tab_renders(): void
    {
        $employee = $this->employee();
        $project = $this->project('P-9', 'Ledger Rewrite');

        $employee->projects()->attach($project, ['role' => 'Developer', 'from_date' => '2026-01-01']);

        \Livewire\Livewire::test(EmployeeProjectsRelationManager::class, [
            'ownerRecord' => $employee,
            'pageClass' => \App\Modules\Employees\Filament\Resources\Employees\Pages\ViewEmployee::class,
        ])
            ->assertSuccessful()
            ->assertSee('Ledger Rewrite');
    }
}
