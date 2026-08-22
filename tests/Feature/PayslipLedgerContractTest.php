<?php

namespace Tests\Feature;

use App\Modules\Advances\Models\Advance;
use App\Modules\Advances\Services\AdvanceService;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Expenses\Services\ExpenseClaimService;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Contracts\AdvanceLedger;
use App\Support\Contracts\NoAdvanceLedger;
use App\Support\Contracts\NoReimbursableClaims;
use App\Support\Contracts\ReimbursableClaims;
use App\Support\PayslipSettlement;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Payroll asks the two ledgers; it does not name them.
 *
 * `advances` and `expenses` both **require** `payroll` — an advance is repaid out of salary and a claim is
 * reimbursed through the payslip — and Payroll reached back to both services to put the figures on a payslip.
 * That made two two-cycles between a module and the one it declares, and they were the **last cycles in the
 * application**: breaking them took the trapped-module count from 3 to 0. See §11 of
 * docs/module-packaging-plan.md.
 *
 * This is the money code, so what is asserted here is not the indirection for its own sake but the three
 * things that could have gone wrong in moving it:
 *
 *  - the contract is bound to the real implementation, not left on the null default;
 *  - the settlement carries the right four values, in particular the *payroll month's* last day rather than
 *    today — dating a July recovery in August is visible on the advance's own history and wrong on any bill
 *    that credits a month's repayments back;
 *  - the licence guard still holds, having moved from Payroll's hooks to the implementations.
 *
 * The behaviour of recovery and settlement themselves is covered where it already was — `AdvanceRecoveryTest`,
 * `ExpenseClaimTest` and the payslip suites — and those files were left alone deliberately: if the inversion
 * changed what payroll pays, they are what says so.
 */
class PayslipLedgerContractTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private \App\Modules\Employees\Models\Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'ledgercontract@test.local'));
        $this->setCurrentTenant();

        foreach (['advances', 'expenses', 'employees', 'payroll'] as $module) {
            $this->setModuleEnabled($module, true);
        }

        $this->employee = \App\Modules\Employees\Models\Employee::create([
            'user_id' => $this->makeUser('Employee', 'borrower2@test.local')->id,
            'employee_id' => 'EMP-LDG',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);

        \App\Modules\Employees\Models\EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 400000,
        ]);
    }

    private function payslipFor(float $advances = 0.0): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
            'advances' => $advances,
        ]);
    }

    /** The bindings the whole inversion rests on. Left on the default, payroll would deduct nothing. */
    public function test_the_ledgers_bind_the_real_implementations(): void
    {
        $this->assertInstanceOf(AdvanceService::class, app(AdvanceLedger::class));
        $this->assertInstanceOf(ExpenseClaimService::class, app(ReimbursableClaims::class));
    }

    /** Both services satisfy the contracts, so a rename on either side is a type error rather than a silence. */
    public function test_both_services_implement_their_contract(): void
    {
        $this->assertInstanceOf(AdvanceLedger::class, app(AdvanceService::class));
        $this->assertInstanceOf(ReimbursableClaims::class, app(ExpenseClaimService::class));
    }

    /**
     * The defaults are honest rather than merely quiet.
     *
     * A company that bought neither module must get a payslip that deducts nothing and reimburses nothing —
     * not an error, and not a silently different figure.
     */
    public function test_the_null_defaults_answer_nothing(): void
    {
        $settlement = new PayslipSettlement(1, 1, 5000.0, '2026-07-31');

        $advances = new NoAdvanceLedger;
        $claims = new NoReimbursableClaims;

        $this->assertSame(0.0, $advances->instalmentFor(1));
        $this->assertSame(0.0, $claims->reimbursableFor(1));
        $this->assertSame([], $claims->pendingReleaseFor(1));

        // And the write paths do nothing at all rather than throwing.
        $advances->recordRecoveryFor($settlement);
        $advances->reopenSettledFor(1);
        $claims->settleForPayslip($settlement);
        $claims->releaseAll([1, 2]);
    }

    /**
     * The settlement is dated by the payroll month, not by the day somebody pressed save.
     *
     * This was `AdvanceService::recoveryDate()` and it moved to `Payslip::settlementOf()`, because "the last
     * day of the payroll month" is a payroll fact and the ledger recording a recovery should not have to know
     * how a payroll month ends. A regression here is invisible on the payslip and wrong on the advance.
     */
    public function test_the_settlement_is_dated_by_the_payroll_month(): void
    {
        $payslip = $this->payslipFor(advances: 12000.0);

        $settlement = $payslip->settlementOf((float) $payslip->advances);

        $this->assertSame($payslip->getKey(), $settlement->payslipId);
        $this->assertSame($payslip->employee_id, $settlement->employeeId);
        $this->assertSame(12000.0, $settlement->amount);
        $this->assertSame(
            \App\Support\PayrollMonth::lastDay($payslip->month, $payslip->fiscalYear)->toDateString(),
            $settlement->effectiveOn,
            'the recovery would be dated by when somebody pressed save',
        );
    }

    /**
     * An unlicensed module answers zero, and the guard is now on its own side.
     *
     * Payroll used to ask `modules()->enabled('advances')` before calling — which was Payroll knowing about
     * Advances by another name. The guard moved into `AdvanceService`, so this asserts it survived the move:
     * without it, a company that switched Advances off would start seeing deductions again.
     */
    public function test_an_unlicensed_ledger_reports_nothing(): void
    {
        $payslip = $this->payslipFor(advances: 0.0);

        Advance::create([
            'employee_id' => $payslip->employee_id,
            'total_amount' => 60000,
            'monthly_instalment' => 10000,
            'started_on' => '2026-07-01',
            'status' => Advance::STATUS_ACTIVE,
        ]);

        $ledger = app(AdvanceLedger::class);

        $this->assertGreaterThan(0.0, $ledger->instalmentFor($payslip->employee_id), 'licensed, so it answers');

        $this->setModuleEnabled('advances', false);

        $this->assertSame(0.0, $ledger->instalmentFor($payslip->employee_id), 'unlicensed, so it answers nothing');
    }

    /** Same guard, same reason, on the claims side. */
    public function test_an_unlicensed_claims_process_reports_nothing(): void
    {
        $payslip = $this->payslipFor(advances: 0.0);

        ExpenseClaim::create([
            'employee_id' => $payslip->employee_id,
            'amount' => 4500,
            'claimed_on' => '2026-07-10',
            'description' => 'Client travel',
            'status' => ExpenseClaim::STATUS_APPROVED,
            'submitted_by' => auth()->id(),
        ]);

        $claims = app(ReimbursableClaims::class);

        $this->assertSame(4500.0, $claims->reimbursableFor($payslip->employee_id));

        $this->setModuleEnabled('expenses', false);

        $this->assertSame(0.0, $claims->reimbursableFor($payslip->employee_id));
    }

    /**
     * Neither Payroll file names either module any more.
     *
     * The architecture half, asserted the way `ProjectsContributionTest` does it: the behaviour tests above
     * would all still pass if somebody "fixed" a future problem by calling the service directly again, and
     * that would restore the cycle while the suite stayed green. Both files are checked because the edge was
     * spread across them.
     */
    public function test_payroll_no_longer_names_either_module(): void
    {
        foreach ([Payslip::class, \App\Modules\Payroll\Services\PayslipService::class] as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());

            foreach (['Modules\\Advances', 'Modules\\Expenses'] as $module) {
                $this->assertStringNotContainsString(
                    $module,
                    $source,
                    $class.' names '.$module.' again, which restores the cycle',
                );
            }
        }
    }

    private function setModuleEnabled(string $module, bool $on): void
    {
        \App\Modules\Core\Models\CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }
}
