<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The payslip comment thread over the API — accounting-implementation-plan.md
 * Phase 7's pair for the employee portal.
 *
 * The whole point is that the gates are the Filament tab's gates, not a second
 * set: view the payslip (own record for an employee, anything for privileged
 * staff) plus the comment permissions the Employee role already holds. So the
 * cases here are the panel's cases — own thread readable and writable, a
 * colleague's forbidden, the author never settable from outside.
 */
class PayslipCommentsApiTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $admin;

    private Employee $employee;

    private Payslip $ownPayslip;

    private Payslip $colleaguesPayslip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('Administrator', 'comments-admin@test.local');
        $this->actingAs($this->admin);
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'commenter@test.local')->id,
            'employee_id' => 'EMP-CMT',
            'gender' => 'Male',
            'phone' => '0300-0000020',
        ]);

        $colleague = Employee::create([
            'user_id' => $this->makeUser('Employee', 'colleague-cmt@test.local')->id,
            'employee_id' => 'EMP-CMT2',
            'gender' => 'Male',
            'phone' => '0300-0000021',
        ]);

        $this->ownPayslip = $this->payslipFor($this->employee);
        $this->colleaguesPayslip = $this->payslipFor($colleague);
    }

    private function payslipFor(Employee $employee): Payslip
    {
        return Payslip::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }

    public function test_the_employee_reads_their_own_thread_oldest_first(): void
    {
        // Two comments (two rows), the staff reply second — a conversation read
        // newest-first is read backwards, so the API keeps the tab's order.
        $first = $this->ownPayslip->comments()->create([
            'user_id' => $this->employee->user_id,
            'body' => 'My overtime is missing.',
        ]);
        $this->ownPayslip->comments()->create([
            'user_id' => $this->admin->id,
            'body' => 'Checking the attendance record now.',
        ]);

        $this->actingAs($this->employee->user);

        $this->getJson("/api/payslips/{$this->ownPayslip->id}/comments")
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.body', 'My overtime is missing.')
            ->assertJsonPath('data.1.author', $this->admin->name);
    }

    public function test_an_employee_cannot_read_a_colleagues_thread(): void
    {
        $this->colleaguesPayslip->comments()->create([
            'user_id' => $this->admin->id,
            'body' => 'A note about somebody else\'s salary.',
        ]);

        $this->actingAs($this->employee->user);

        $this->getJson("/api/payslips/{$this->colleaguesPayslip->id}/comments")->assertForbidden();
        $this->postJson("/api/payslips/{$this->colleaguesPayslip->id}/comments", [
            'body' => 'Should not land.',
        ])->assertForbidden();

        $this->assertSame(1, $this->colleaguesPayslip->comments()->count());
    }

    public function test_the_employee_posts_to_their_own_thread_as_themselves(): void
    {
        $this->actingAs($this->employee->user);

        $this->postJson("/api/payslips/{$this->ownPayslip->id}/comments", [
            'body' => 'The device allowance looks wrong.',
            // The author is whoever is signed in — not settable from the payload.
            'user_id' => $this->admin->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'The device allowance looks wrong.')
            ->assertJsonPath('data.user_id', $this->employee->user_id);

        $comment = $this->ownPayslip->comments()->first();
        $this->assertSame($this->employee->user_id, $comment->user_id);
    }

    public function test_a_comment_needs_a_body(): void
    {
        $this->actingAs($this->employee->user);

        $this->postJson("/api/payslips/{$this->ownPayslip->id}/comments", ['body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_staff_read_and_reply_on_any_payslip(): void
    {
        // Privileged staff see every payslip in the panel; the API agrees.
        $this->ownPayslip->comments()->create([
            'user_id' => $this->employee->user_id,
            'body' => 'My overtime is missing.',
        ]);

        $this->actingAs($this->admin);

        $this->getJson("/api/payslips/{$this->ownPayslip->id}/comments")
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->postJson("/api/payslips/{$this->ownPayslip->id}/comments", [
            'body' => 'Fixed — see the corrected payslip.',
        ])->assertCreated();

        $this->assertSame(2, $this->ownPayslip->comments()->count());
    }
}
