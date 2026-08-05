<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Expenses\Notifications\ExpenseClaimSubmitted;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Telling the right people something is waiting for them, without that being able to
 * fail the thing they are waiting for.
 *
 * Spatie's `permission()` scope throws when the named permission has no row, which is a
 * fact about the database rather than about the caller — and every caller is a
 * notification sent from a `created` hook, after the record is already committed. So the
 * throw turned a saved expense claim into a 500 and told the employee their submission
 * had failed while it sat in the table. That is the case these tests pin.
 */
class NotifyingApproversTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'submitter@test.local'));
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'claimant@test.local')->id,
            'employee_id' => 'EMP-NOTIFY',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);
    }

    private function claim(): ExpenseClaim
    {
        return ExpenseClaim::create([
            'employee_id' => $this->employee->id,
            'claimed_on' => '2026-08-05',
            'description' => 'Taxi to the client',
            'amount' => 1500,
            'submitted_by' => auth()->id(),
        ]);
    }

    private function forget(string $permission): void
    {
        Permission::where('name', $permission)->delete();

        // Spatie caches the whole permission table, so a deleted row is still found
        // until the cache is dropped — and the exception this is about comes from the
        // cache, not the query.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_a_claim_is_saved_even_when_the_permission_does_not_exist(): void
    {
        // The bug: this threw PermissionDoesNotExist from the created hook, after the
        // insert, so the row existed and the employee was told it had failed.
        $this->forget(ExpenseClaim::APPROVE_PERMISSION);

        $claim = $this->claim();

        $this->assertDatabaseHas('expense_claims', ['id' => $claim->id, 'amount' => 1500]);
        $this->assertSame(ExpenseClaim::STATUS_PENDING, $claim->status);
    }

    public function test_the_missing_permission_is_logged_with_what_to_run(): void
    {
        // Not swallowed: a claim nobody is waiting on is a real problem, and whoever
        // reads the log needs the remedy rather than the symptom.
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'ExpenseClaimApprove')
                && str_contains($message, 'PermissionSeeder'));

        $this->forget(ExpenseClaim::APPROVE_PERMISSION);

        $this->claim();
    }

    public function test_nobody_is_notified_when_nobody_can_be_found(): void
    {
        Notification::fake();

        $this->forget(ExpenseClaim::APPROVE_PERMISSION);

        $this->claim();

        Notification::assertNothingSent();
    }

    /** And with the permission in place it behaves exactly as before. */
    public function test_an_approver_is_notified_when_the_permission_exists(): void
    {
        Notification::fake();

        $approver = $this->makeUser('Administrator', 'approver@test.local');
        $approver->givePermissionTo(ExpenseClaim::APPROVE_PERMISSION);

        $this->claim();

        Notification::assertSentTo($approver, ExpenseClaimSubmitted::class);
    }

    public function test_the_submitter_is_never_notified_of_their_own_claim(): void
    {
        // The point of an approver is that it is somebody else, permission or not.
        Notification::fake();

        $submitter = auth()->user();
        $submitter->givePermissionTo(ExpenseClaim::APPROVE_PERMISSION);

        $this->claim();

        Notification::assertNotSentTo($submitter, ExpenseClaimSubmitted::class);
    }

    /**
     * The other two callers of the same scope, which would have failed the same way:
     * a payslip being sent and an employee change request being raised.
     */
    public function test_the_other_notification_lookups_are_equally_safe(): void
    {
        $this->forget('PayslipUpdate');
        $this->forget('EmployeeChangeApprove');

        $this->assertSame(0, User::holdingPermission('PayslipUpdate')->count());
        $this->assertSame(0, User::holdingPermission('EmployeeChangeApprove')->count());
    }

    public function test_it_still_finds_the_holders_of_a_permission_that_exists(): void
    {
        $holder = $this->makeUser('Administrator', 'holder@test.local');
        $holder->givePermissionTo(ExpenseClaim::APPROVE_PERMISSION);

        $found = User::holdingPermission(ExpenseClaim::APPROVE_PERMISSION)->pluck('id');

        $this->assertTrue($found->contains($holder->id));
    }
}
