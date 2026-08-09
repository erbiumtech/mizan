<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Expenses\Notifications\ExpenseClaimDecided;
use App\Modules\Expenses\Notifications\ExpenseClaimSubmitted;
use App\Modules\Expenses\Services\ExpenseClaimService;
use App\Modules\Payroll\Models\Payslip;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Expense claims: submit with a receipt, approve or refuse with a reason, reimburse
 * through payroll.
 *
 * Before this, a reimbursement was a number typed into a payslip — no receipt, no
 * approver, no record of what it was for, and nothing to show anyone who asked why
 * somebody was paid 25,000 more in March.
 *
 * The reimbursement path is AdvanceRecovery's shape, so the cases that matter are
 * the same: payroll recalculates a payslip on every save, so an approved claim must
 * be settled exactly once, and a deleted payslip must leave the employee owed again.
 */
class ExpenseClaimTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    private $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->approver = $this->makeUser('Administrator', 'approver@test.local');
        $this->actingAs($this->approver);
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'expenses'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $claimant = $this->makeUser('Employee', 'claimant@test.local');
        $claimant->update(['name' => 'Nadeem Yahya']);

        $this->employee = Employee::create([
            'user_id' => $claimant->id,
            'employee_id' => 'EMP-CLAIM',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 400000,
        ]);
    }

    private function claim(float $amount, array $attributes = []): ExpenseClaim
    {
        return ExpenseClaim::create(array_merge([
            'employee_id' => $this->employee->id,
            'claimed_on' => '2026-08-02',
            'description' => 'Taxi to the client',
            'amount' => $amount,
            'submitted_by' => $this->employee->user_id,
        ], $attributes));
    }

    private function payslip(string $month = 'August'): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => $month,
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }

    // ---- Submitting and deciding -------------------------------------------

    public function test_submitting_notifies_whoever_can_approve(): void
    {
        Notification::fake();

        $this->claim(5000);

        Notification::assertSentTo($this->approver, ExpenseClaimSubmitted::class);
    }

    public function test_the_person_who_submitted_is_not_asked_to_approve_their_own(): void
    {
        // Even when they hold the permission: an approver is somebody else, which is
        // the whole point of having one.
        Notification::fake();

        $submitter = $this->makeUser('Administrator', 'selfsubmit@test.local');

        $this->claim(5000, ['submitted_by' => $submitter->id]);

        Notification::assertNotSentTo($submitter, ExpenseClaimSubmitted::class);
        Notification::assertSentTo($this->approver, ExpenseClaimSubmitted::class);
    }

    public function test_approving_tells_the_claimant(): void
    {
        Notification::fake();

        $claim = $this->claim(5000);
        $claim->approve($this->approver);

        $this->assertSame(ExpenseClaim::STATUS_APPROVED, $claim->fresh()->status);
        $this->assertSame($this->approver->id, $claim->fresh()->decided_by);
        Notification::assertSentTo($this->employee->user, ExpenseClaimDecided::class);
    }

    public function test_a_refusal_carries_its_reason(): void
    {
        $claim = $this->claim(5000);
        $claim->refuse($this->approver, 'No receipt, and the trip is not on the project log.');

        $claim->refresh();

        $this->assertSame(ExpenseClaim::STATUS_REFUSED, $claim->status);
        $this->assertStringContainsString('not on the project log', $claim->refusal_reason);
    }

    public function test_a_refusal_without_a_reason_is_refused(): void
    {
        $this->expectExceptionMessage('A refusal needs a reason.');

        $this->claim(5000)->refuse($this->approver, '   ');
    }

    /**
     * Enforced on the model, not the policy: Administrators pass every policy check,
     * so a rule that has to hold for everybody cannot live in one.
     */
    public function test_nobody_decides_their_own_claim(): void
    {
        $claim = $this->claim(5000, ['submitted_by' => $this->approver->id]);

        $this->expectExceptionMessage('cannot be decided by the person who submitted it');

        $claim->approve($this->approver);
    }

    public function test_a_decided_claim_cannot_be_decided_again(): void
    {
        $claim = $this->claim(5000);
        $claim->approve($this->approver);

        $this->expectExceptionMessage('already approved');

        $claim->refuse($this->approver, 'Changed my mind.');
    }

    // ---- Reaching the payslip ----------------------------------------------

    public function test_an_approved_claim_reaches_the_next_payslip(): void
    {
        $claim = $this->claim(5000);
        $claim->approve($this->approver);

        $payslip = $this->payslip();

        $this->assertSame(5000.0, (float) $payslip->fresh()->expense_reimbursement);
        $this->assertSame(ExpenseClaim::STATUS_SETTLED, $claim->fresh()->status);
        $this->assertSame($payslip->id, $claim->fresh()->payslip_id);
    }

    public function test_a_pending_claim_reaches_nothing(): void
    {
        // Reimbursing before anyone approves would make the approval decorative.
        $this->claim(5000);

        $this->assertSame(0.0, (float) $this->payslip()->fresh()->expense_reimbursement);
    }

    public function test_a_refused_claim_reaches_nothing(): void
    {
        $this->claim(5000)->refuse($this->approver, 'Personal.');

        $this->assertSame(0.0, (float) $this->payslip()->fresh()->expense_reimbursement);
    }

    public function test_several_claims_are_reimbursed_together(): void
    {
        foreach ([5000, 2500, 900] as $amount) {
            $this->claim($amount)->approve($this->approver);
        }

        $this->assertSame(8400.0, (float) $this->payslip()->fresh()->expense_reimbursement);
        $this->assertSame(3, ExpenseClaim::where('status', ExpenseClaim::STATUS_SETTLED)->count());
    }

    /** Payroll recalculates on every save; a second pass must not pay it again. */
    public function test_re_saving_the_payslip_does_not_reimburse_twice(): void
    {
        $this->claim(5000)->approve($this->approver);

        $payslip = $this->payslip();

        $payslip->update(['paid_days' => 21]);
        $payslip->update(['paid_days' => 22]);

        $this->assertSame(5000.0, (float) $payslip->fresh()->expense_reimbursement);
        $this->assertSame(1, ExpenseClaim::where('status', ExpenseClaim::STATUS_SETTLED)->count());
    }

    public function test_deleting_the_payslip_leaves_the_employee_owed_again(): void
    {
        $claim = $this->claim(5000);
        $claim->approve($this->approver);

        $payslip = $this->payslip();
        $this->assertSame(ExpenseClaim::STATUS_SETTLED, $claim->fresh()->status);

        $payslip->delete();

        $claim->refresh();
        $this->assertSame(ExpenseClaim::STATUS_APPROVED, $claim->status);
        $this->assertNull($claim->payslip_id);
    }

    public function test_a_claim_approved_after_the_payslip_waits_for_the_next_one(): void
    {
        $august = $this->payslip('August');

        $this->assertSame(0.0, (float) $august->fresh()->expense_reimbursement);

        $claim = $this->claim(5000);
        $claim->approve($this->approver);

        $september = $this->payslip('September');

        $this->assertSame(5000.0, (float) $september->fresh()->expense_reimbursement);
        $this->assertSame(0.0, (float) $august->fresh()->expense_reimbursement, 'August is left alone');
        $this->assertSame($september->id, $claim->fresh()->payslip_id);
    }

    /**
     * An amount typed on the payslip still wins — paying a reimbursement outside the
     * claim process is a legitimate correction — but then it covers what it can and
     * the rest stays owed.
     */
    public function test_a_typed_reimbursement_covers_what_it_can(): void
    {
        $covered = $this->claim(5000);
        $covered->approve($this->approver);

        $alsoOwed = $this->claim(9000);
        $alsoOwed->approve($this->approver);

        $payslip = Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'August',
            'total_working_days' => 22,
            'paid_days' => 22,
            'expense_reimbursement' => 5000,
        ]);

        $this->assertSame(5000.0, (float) $payslip->fresh()->expense_reimbursement);
        $this->assertSame(ExpenseClaim::STATUS_SETTLED, $covered->fresh()->status);
        $this->assertSame(ExpenseClaim::STATUS_APPROVED, $alsoOwed->fresh()->status, 'still owed');
    }

    public function test_the_reimbursement_is_added_to_net_pay(): void
    {
        $this->claim(5000)->approve($this->approver);

        $withClaim = $this->payslip()->fresh();

        $this->assertSame(
            round((float) $withClaim->total_earnings + 5000 - (float) $withClaim->total_deductions, 2),
            round((float) $withClaim->net_salary, 2),
        );
    }

    public function test_with_the_module_off_payroll_carries_on_without_claims(): void
    {
        $this->claim(5000)->approve($this->approver);

        CompanyModule::where('module', 'expenses')->update(['enabled' => false]);
        modules()->flush();

        $this->assertSame(0.0, (float) $this->payslip()->fresh()->expense_reimbursement);
    }

    public function test_what_an_employee_is_owed_is_answerable(): void
    {
        $this->claim(5000)->approve($this->approver);
        $this->claim(2500)->approve($this->approver);
        $this->claim(900);

        $this->assertSame(
            7500.0,
            app(ExpenseClaimService::class)->reimbursableFor($this->employee->id),
            'approved and unpaid only',
        );
    }
}
