<?php

namespace Tests\Feature;

use App\Modules\Attendance\Filament\Resources\EmployeeWorkPatterns\Pages\ListEmployeeWorkPatterns;
use App\Modules\Attendance\Filament\Resources\RestDaySwaps\Pages\ListRestDaySwaps;
use App\Modules\Attendance\Models\EmployeeWorkPattern;
use App\Modules\Attendance\Models\RestDaySwap;
use App\Modules\Attendance\Models\WorkPattern;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Pattern assignments and rest-day swaps are scoped like every other employee-keyed list: your own and
 * your reporting line's, unless your role sees everyone. They were unscoped, so every member of staff could
 * read every colleague's working week and swapped days.
 */
class WorkPatternListsScopedTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $bilalUser;

    private Employee $bilal;

    private Employee $ayesha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bilalUser = $this->makeUser('Employee', 'bilal-wp@test.local');
        $this->bilal = Employee::create(['user_id' => $this->bilalUser->id, 'employee_id' => 'EMP-WP-1', 'gender' => 'Male', 'is_active' => true]);
        $this->ayesha = Employee::create(['user_id' => $this->makeUser('Employee', 'ayesha-wp@test.local')->id, 'employee_id' => 'EMP-WP-2', 'gender' => 'Female', 'is_active' => true]);
        // Filament sets a tenant for a signed-in user; nobody signed in is a state the panel never has.
        $this->actingAs($this->makeUser('Administrator', 'admin-wp@test.local'));
        $this->setCurrentTenant();

        $pattern = WorkPattern::create(['name' => 'Six days']);

        foreach ([$this->bilal, $this->ayesha] as $employee) {
            EmployeeWorkPattern::create(['employee_id' => $employee->id, 'work_pattern_id' => $pattern->id, 'from_date' => '2026-01-01']);
            RestDaySwap::create(['employee_id' => $employee->id, 'work_on' => '2026-11-08', 'rest_on' => '2026-11-10']);
        }
    }

    public function test_an_employee_sees_only_their_own_assignment_and_swap(): void
    {
        $this->actingAs($this->bilalUser);

        Livewire::test(ListEmployeeWorkPatterns::class)->loadTable()
            ->assertCanSeeTableRecords(EmployeeWorkPattern::query()->where('employee_id', $this->bilal->id)->get())
            ->assertCanNotSeeTableRecords(EmployeeWorkPattern::query()->where('employee_id', $this->ayesha->id)->get());

        Livewire::test(ListRestDaySwaps::class)->loadTable()
            ->assertCanSeeTableRecords(RestDaySwap::query()->where('employee_id', $this->bilal->id)->get())
            ->assertCanNotSeeTableRecords(RestDaySwap::query()->where('employee_id', $this->ayesha->id)->get());
    }

    public function test_a_manager_sees_everyone(): void
    {
        $this->actingAs($this->makeUser('Manager', 'manager-wp@test.local'));

        Livewire::test(ListEmployeeWorkPatterns::class)->loadTable()->assertCanSeeTableRecords(EmployeeWorkPattern::all());
        Livewire::test(ListRestDaySwaps::class)->loadTable()->assertCanSeeTableRecords(RestDaySwap::all());
    }
}
