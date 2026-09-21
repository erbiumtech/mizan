<?php

namespace Tests\Feature;

use App\Modules\Employees\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Modules\Employees\Filament\Resources\Employees\RelationManagers\JobHistoryRelationManager;
use App\Modules\Employees\Models\Employee;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Job history, on the screen — `docs/hrms-plan.md`'s "Not built: any UI for the history itself."
 *
 * The rows were already being written by the employee form; nothing could read them without a database
 * client. This asserts the two things that make the tab trustworthy: it shows what was recorded at the time
 * rather than what the record says today, and nobody can type into it.
 */
class JobHistoryScreenTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-21 10:00:00');

        $this->actingAs($this->makeUser('Administrator', 'hr@test.local'));
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'employee_id' => 'EMP-1',
            'name' => 'Ali Raza',
            'gender' => 'Male',
            'is_active' => true,
            'designation' => 'Team Lead',
            'department' => 'Engineering',
            'date_of_joining' => '2024-03-01',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function history(array $attributes): void
    {
        $this->employee->jobHistory()->create($attributes + ['department' => 'Engineering']);
    }

    public function test_it_lists_every_role_as_it_was_recorded(): void
    {
        $this->history(['effective_from' => '2024-03-01', 'designation' => 'Developer', 'employment_type' => 'probation']);
        $this->history(['effective_from' => '2025-04-01', 'designation' => 'Senior Developer', 'employment_type' => 'permanent']);
        $this->history(['effective_from' => '2026-06-01', 'designation' => 'Team Lead', 'employment_type' => 'permanent']);

        Livewire::test(JobHistoryRelationManager::class, [
            'ownerRecord' => $this->employee,
            'pageClass' => ViewEmployee::class,
        ])
            ->assertSuccessful()
            // The title they *held then*, not the one on the record today.
            ->assertSee('Developer')
            ->assertSee('Senior Developer')
            ->assertSee('Team Lead')
            ->assertSee('Probation');
    }

    /** A change agreed now and effective later is a row that has not happened yet, and the tab says so. */
    public function test_a_future_dated_change_is_marked(): void
    {
        $this->history(['effective_from' => '2026-06-01', 'designation' => 'Team Lead']);
        $this->history(['effective_from' => '2026-12-01', 'designation' => 'Engineering Manager']);

        Livewire::test(JobHistoryRelationManager::class, [
            'ownerRecord' => $this->employee,
            'pageClass' => ViewEmployee::class,
        ])
            ->assertSuccessful()
            ->assertSee('Engineering Manager')
            ->assertSee('Takes effect on this date');
    }

    /** Written by a change to the employee, never typed here — so there is nothing to type with. */
    public function test_it_is_read_only(): void
    {
        $this->history(['effective_from' => '2026-06-01', 'designation' => 'Team Lead']);

        $manager = Livewire::test(JobHistoryRelationManager::class, [
            'ownerRecord' => $this->employee,
            'pageClass' => ViewEmployee::class,
        ]);

        $manager->assertSuccessful();
        $this->assertTrue($manager->instance()->isReadOnly());
        $manager->assertActionDoesNotExist('create');
    }

    public function test_an_employee_with_no_changes_gets_a_sentence_rather_than_an_empty_table(): void
    {
        Livewire::test(JobHistoryRelationManager::class, [
            'ownerRecord' => $this->employee,
            'pageClass' => ViewEmployee::class,
        ])
            ->assertSuccessful()
            ->assertSee('No job changes recorded');
    }
}
