<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\ListPayslips;
use App\Modules\Payroll\Models\Payslip;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The reason an employee gives for rejecting a payslip: collected, stored, and — as of now — *shown*.
 *
 * The collecting half was already built and already required: **Reject Payslip** opens a modal with a
 * required Reason, `Payslip::recordEmployeeReview()` stores it, `PayslipRejected` emails it to the payroll
 * team, `CopyReviewOntoPayment` copies it onto the salary payment, and the bank file names it as the reason
 * a row is held back. `PayslipReviewTest` covers the model. What nothing covered was the **action** — the
 * form that collects the reason — and what nothing did at all was put it on the screen the payroll team
 * works from: a rejected payslip was a red badge and a question whose answer was in somebody's inbox.
 *
 * So these tests are in two halves, and both are about the reason rather than the rejection:
 *
 *  - the action *requires* one and stores what was typed, through Livewire rather than through the model,
 *    because the model has never been the part that could quietly drop it;
 *  - the list *states* it under the badge, truncated, with the whole sentence on hover.
 */
class PayslipRejectionReasonTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // The employee themselves: `canReview()` allows the owning employee and nobody else, which is what
        // makes this the flow a person actually goes through rather than an administrator's shortcut.
        $this->actingAs($this->makeUser('Employee', 'rejecter@test.local'));
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'user_id' => auth()->id(),
            'employee_id' => 'EMP-REJECT',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);
    }

    /** The modal will not submit without one — the point being that it never has, and now it is asserted. */
    public function test_the_reject_action_refuses_to_submit_without_a_reason(): void
    {
        $payslip = $this->payslip();

        Livewire::test(ListPayslips::class)
            ->callTableAction('rejectPayslip', $payslip, ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => ['required']]);

        $this->assertSame(Payslip::REVIEW_PENDING, $payslip->refresh()->employee_review);
    }

    /** And what was typed is what is stored, not a summary of it. */
    public function test_the_reason_typed_into_the_action_is_what_is_stored(): void
    {
        $payslip = $this->payslip();

        Livewire::test(ListPayslips::class)
            ->callTableAction('rejectPayslip', $payslip, ['reason' => 'Overtime for the 14th is missing'])
            ->assertHasNoTableActionErrors();

        $payslip->refresh();

        $this->assertSame(Payslip::REVIEW_REJECTED, $payslip->employee_review);
        $this->assertSame('Overtime for the 14th is missing', $payslip->employee_rejection_reason);
        $this->assertNotNull($payslip->employee_reviewed_at);
    }

    /**
     * Accepting stores no reason, which is the other half of the column being null most of the time.
     *
     * Worth pinning because `recordEmployeeReview()` takes the reason as an optional second argument: an
     * accept that carried one would put an objection on a payslip nobody objected to.
     */
    public function test_accepting_records_no_reason(): void
    {
        $payslip = $this->payslip();

        Livewire::test(ListPayslips::class)
            ->callTableAction('acceptPayslip', $payslip);

        $payslip->refresh();

        $this->assertSame(Payslip::REVIEW_ACCEPTED, $payslip->employee_review);
        $this->assertNull($payslip->employee_rejection_reason);
    }

    /** The list says why, under the badge that says it was rejected. */
    public function test_the_list_shows_the_reason_under_the_review_badge(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime for the 14th is missing');

        Livewire::test(ListPayslips::class)
            ->assertSee('rejected')
            ->assertSee('Overtime for the 14th is missing');
    }

    /**
     * A long objection is truncated rather than allowed to reflow the row.
     *
     * The whole sentence is still reachable — it is the column's tooltip, and it is on the payment this
     * rejection holds back — so nothing is lost by keeping the list readable.
     */
    public function test_a_long_reason_is_truncated_in_the_list(): void
    {
        $payslip = $this->payslip();
        $long = 'The overtime I worked on the 14th and again on the 21st has not been included, and the '
            .'petrol allowance looks like last month\'s figure rather than this one.';

        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, $long);

        // The truncated form itself, ellipsis included, and the tail present only because the tooltip carries
        // it. Asserting that the tail is *absent* would fail for the right reason — the whole sentence is
        // deliberately still on the page — and asserting the whole string in one piece fails for a boring
        // one: the apostrophe is escaped differently inside the tooltip attribute than in the cell.
        Livewire::test(ListPayslips::class)
            ->assertSee(Str::limit($long, 60))
            ->assertSee('petrol allowance looks like');
    }

    /** An accepted payslip carries no note at all, so the column stays one line for almost every row. */
    public function test_an_accepted_payslip_shows_no_note(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        Livewire::test(ListPayslips::class)
            ->assertSee('accepted')
            ->assertDontSee('on behalf');
    }

    private function payslip(): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'net_salary' => 50000,
        ]);
    }
}
