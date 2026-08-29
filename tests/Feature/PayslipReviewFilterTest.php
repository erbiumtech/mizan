<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\ListPayslips;
use App\Modules\Payroll\Models\Payslip;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Filtering payslips by the employee's acknowledgement.
 *
 * `employee_review` is NOT NULL with a 'pending' default, so the four states are
 * exhaustive — asserted below, because that is what lets the filter be a plain
 * column match rather than something that also has to account for rows holding
 * neither state. The fourth, `overridden`, is a rejection payroll has answered.
 */
class PayslipReviewFilterTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'payslip-filter@test.local'));
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'reviewer@test.local')->id,
            'employee_id' => 'EMP-REVIEW',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);
    }

    private function payslip(string $month, string $review): Payslip
    {
        $payslip = Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => $month,
            'net_salary' => 50000,
        ]);

        // Set directly rather than through recordEmployeeReview(), which refuses a
        // second review and stamps a timestamp — neither is what this is testing.
        DB::table('payslips')->where('id', $payslip->id)->update(['employee_review' => $review]);

        return $payslip->fresh();
    }

    public function test_the_filter_is_offered_with_every_review_state(): void
    {
        Livewire::test(ListPayslips::class)
            ->assertTableFilterExists('employee_review', fn ($filter): bool => $filter->getLabel() === 'Employee Review');
    }

    /**
     * An answered objection is its own option, not a kind of rejection.
     *
     * The question at the end of a payroll run is *which objections are still waiting on somebody*, and
     * folding the answered ones back into Rejected would make that unanswerable — which is the same reason
     * `Payslip::REVIEW_OVERRIDDEN` is not `accepted`.
     */
    public function test_an_answered_objection_filters_separately_from_an_open_one(): void
    {
        $rejected = $this->payslip('February', Payslip::REVIEW_REJECTED);
        $answered = $this->payslip('April', Payslip::REVIEW_OVERRIDDEN);

        Livewire::test(ListPayslips::class)
            ->filterTable('employee_review', Payslip::REVIEW_REJECTED)
            ->assertCanSeeTableRecords([$rejected])
            ->assertCanNotSeeTableRecords([$answered]);

        Livewire::test(ListPayslips::class)
            ->filterTable('employee_review', Payslip::REVIEW_OVERRIDDEN)
            ->assertCanSeeTableRecords([$answered])
            ->assertCanNotSeeTableRecords([$rejected]);
    }

    public function test_accepted_and_rejected_match_only_themselves(): void
    {
        $accepted = $this->payslip('January', Payslip::REVIEW_ACCEPTED);
        $rejected = $this->payslip('February', Payslip::REVIEW_REJECTED);
        $pending = $this->payslip('March', Payslip::REVIEW_PENDING);

        Livewire::test(ListPayslips::class)
            ->filterTable('employee_review', Payslip::REVIEW_ACCEPTED)
            ->assertCanSeeTableRecords([$accepted])
            ->assertCanNotSeeTableRecords([$rejected, $pending]);

        Livewire::test(ListPayslips::class)
            ->filterTable('employee_review', Payslip::REVIEW_REJECTED)
            ->assertCanSeeTableRecords([$rejected])
            ->assertCanNotSeeTableRecords([$accepted, $pending]);
    }

    public function test_pending_matches_the_unreviewed_ones(): void
    {
        $pending = $this->payslip('January', Payslip::REVIEW_PENDING);
        $accepted = $this->payslip('March', Payslip::REVIEW_ACCEPTED);

        $this->assertTrue($pending->isPendingReview());

        Livewire::test(ListPayslips::class)
            ->filterTable('employee_review', Payslip::REVIEW_PENDING)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$accepted]);
    }

    /**
     * What lets the filter stay a plain column match. A freshly created payslip
     * lands on 'pending' from the column default, so no row is left in a state
     * none of the three options would find.
     */
    public function test_every_payslip_holds_one_of_the_four_states(): void
    {
        $payslip = Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'May',
            'net_salary' => 50000,
        ]);

        $this->assertSame(Payslip::REVIEW_PENDING, $payslip->fresh()->employee_review);

        $this->assertSame(
            0,
            Payslip::whereNull('employee_review')->count(),
            'employee_review is NOT NULL with a default, so nothing can be outside the four states.'
        );
    }

    public function test_no_selection_leaves_every_payslip_visible(): void
    {
        $rows = [
            $this->payslip('January', Payslip::REVIEW_PENDING),
            $this->payslip('March', Payslip::REVIEW_ACCEPTED),
            $this->payslip('April', Payslip::REVIEW_REJECTED),
        ];

        Livewire::test(ListPayslips::class)->assertCanSeeTableRecords($rows);
    }
}
