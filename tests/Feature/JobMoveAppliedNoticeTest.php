<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Modules\Employees\Models\Employee;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * "Record a transfer" and "Record a promotion" say whether the record moved.
 *
 * A move changes the record only when its row is the one in force today. One dated before a later row —
 * including the row an ordinary edit of a job fact writes today — is history and never applies; one dated
 * ahead waits for its day. Both used to answer "Transfer recorded." while the record still showed the old
 * department.
 */
class JobMoveAppliedNoticeTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $admin;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('Administrator', 'moves-admin@test.local');
        $this->actingAs($this->admin);
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'employee_id' => 'EMP-MOVE-1', 'name' => 'Mover', 'gender' => 'Male', 'is_active' => true,
            'department' => 'IT', 'designation' => 'Backend Developer', 'date_of_joining' => '2024-01-01',
        ]);

        // An ordinary edit of a job fact today writes today's history row.
        $this->employee->update(['department' => 'Office Staff']);
    }

    private function transfer(string $effectiveFrom)
    {
        return Livewire::test(ViewEmployee::class, ['record' => $this->employee->getKey()])
            ->callAction('recordTransfer', ['department' => 'IT', 'effective_from' => $effectiveFrom, 'reason' => 'test'])
            ->assertHasNoActionErrors();
    }

    public function test_a_transfer_effective_today_is_applied_and_says_so(): void
    {
        $this->transfer(now()->toDateString())->assertNotified('Transfer recorded and applied.');

        $this->assertSame('IT', $this->employee->fresh()->department);
    }

    public function test_a_transfer_dated_before_a_later_change_says_it_was_not_applied(): void
    {
        $this->transfer(now()->subDays(10)->toDateString())
            ->assertNotified('Transfer recorded in the history, but not applied to the record.');

        $this->assertSame('Office Staff', $this->employee->fresh()->department);
    }

    public function test_a_future_transfer_says_when_it_takes_effect(): void
    {
        $on = now()->addDays(14);

        $this->transfer($on->toDateString())
            ->assertNotified('Transfer recorded. It takes effect on '.$on->format('d M Y').'.');

        $this->assertSame('Office Staff', $this->employee->fresh()->department);
    }

    public function test_a_backdated_promotion_says_the_same(): void
    {
        $this->employee->update(['designation' => 'Frontend Developer']);

        Livewire::test(ViewEmployee::class, ['record' => $this->employee->getKey()])
            ->callAction('recordPromotion', ['designation' => 'Senior Full Stack Developer', 'effective_from' => now()->subDays(3)->toDateString()])
            ->assertNotified('Promotion recorded in the history, but not applied to the record.');
    }
}
