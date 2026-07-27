<?php

namespace Tests\Feature;

use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\ProjectsRelationManager;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\RelationManagers\EmployeesRelationManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\Feature\Concerns\MakesProjects;
use Tests\TestCase;

class ProjectAssignmentTest extends TestCase
{
    use InteractsWithTenant;
    use MakesProjects;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->setCurrentTenant();
    }

    private function teamTable($project)
    {
        return Livewire::test(EmployeesRelationManager::class, [
            'ownerRecord' => $project,
            'pageClass' => EditProject::class,
        ]);
    }

    public function test_attaching_stores_the_pivot_data(): void
    {
        $project = $this->makeProject();
        $employee = $this->makeEmployee('EMP-1');

        $this->teamTable($project)
            ->callTableAction('attach', data: [
                'recordId' => $employee->id,
                'role' => 'Backend lead',
                'allocation_pct' => 60,
                'from_date' => today()->toDateString(),
            ])
            ->assertHasNoTableActionErrors();

        $pivot = $project->employees()->first()->pivot;

        $this->assertSame('Backend lead', $pivot->role);
        $this->assertSame(60.0, (float) $pivot->allocation_pct);
        $this->assertNull($pivot->to_date);
    }

    public function test_a_second_open_stint_is_rejected_with_a_readable_message(): void
    {
        $project = $this->makeProject();
        $employee = $this->makeEmployee('EMP-1');
        $project->assign($employee);

        $this->teamTable($project)
            ->callTableAction('attach', data: [
                'recordId' => $employee->id,
                'from_date' => today()->addDay()->toDateString(),
            ])
            ->assertHasTableActionErrors(['recordId']);

        $this->assertCount(1, $project->refresh()->employees);
    }

    public function test_re_assignment_after_an_ended_stint_is_allowed_and_both_are_kept(): void
    {
        $project = $this->makeProject();
        $employee = $this->makeEmployee('EMP-1');

        $project->assign($employee, ['from_date' => today()->subMonths(2)->toDateString()]);
        $project->endAssignment($employee, today()->subMonth()->toDateString());

        $project->assign($employee, ['from_date' => today()->toDateString()]);

        $this->assertCount(2, $project->refresh()->employees);
        $this->assertCount(1, $project->currentEmployees()->get());
    }

    public function test_end_assignment_sets_the_end_date_and_keeps_the_row(): void
    {
        $project = $this->makeProject();
        $employee = $this->makeEmployee('EMP-1');
        $project->assign($employee);

        $this->teamTable($project)
            ->callTableAction('endAssignment', $employee->getKey())
            ->assertHasNoTableActionErrors();

        $this->assertCount(1, $project->refresh()->employees);
        $this->assertSame(
            today()->toDateString(),
            Carbon::parse($project->employees()->first()->pivot->to_date)->toDateString()
        );
    }

    public function test_a_duplicate_start_date_is_refused_by_the_model(): void
    {
        $project = $this->makeProject();
        $employee = $this->makeEmployee('EMP-1');

        $project->assign($employee, ['from_date' => today()->toDateString()]);
        $project->endAssignment($employee, today()->subDay()->toDateString());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('starting');

        $project->assign($employee, ['from_date' => today()->toDateString()]);
    }

    public function test_current_filter_hides_ended_stints(): void
    {
        $project = $this->makeProject();
        $current = $this->makeEmployee('EMP-CURRENT');
        $past = $this->makeEmployee('EMP-PAST');

        $project->assign($current);
        $project->assign($past, ['from_date' => today()->subMonths(3)->toDateString()]);
        $project->endAssignment($past, today()->subMonth()->toDateString());

        // Default filter is "current only".
        $this->teamTable($project)
            ->assertCanSeeTableRecords([$current])
            ->assertCanNotSeeTableRecords([$past]);

        $this->assertCount(1, $project->currentEmployees()->get());
        $this->assertSame(2, $project->employees()->count());
    }

    public function test_the_employee_page_lists_their_assignments_read_only(): void
    {
        $employee = $this->makeEmployee('EMP-1');
        $mine = $this->makeProject(['name' => 'Mine']);
        $other = $this->makeProject(['name' => 'Not mine']);

        $mine->assign($employee);

        $component = Livewire::test(ProjectsRelationManager::class, [
            'ownerRecord' => $employee,
            'pageClass' => ViewEmployee::class,
        ]);

        $component
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other])
            // Managed from the project side only — no write actions here.
            ->assertTableActionDoesNotExist('attach')
            ->assertTableActionDoesNotExist('detach')
            ->assertTableActionDoesNotExist('edit');
    }
}
