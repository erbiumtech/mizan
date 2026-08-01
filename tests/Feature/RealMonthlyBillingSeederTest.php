<?php

namespace Tests\Feature;

use App\Modules\Advances\Models\Advance;
use App\Modules\Billing\Models\BillingRun;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Payroll\Models\Payslip;
use Database\Seeders\Production\RealEmployeeSeeder;
use Database\Seeders\Production\RealMonthlyBillingSeeder;
use Database\Seeders\TransactionTypeSeeder;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The seeder that loads a month of the salaries spreadsheet.
 *
 * Its figures are the point of it, so they are checked against the sheet's own
 * totals rather than against what the code happens to produce. The sheet's salary
 * total covers its PKR rows only; the EUR component it lists separately is added
 * here at the rate the seeder converts it at.
 */
class RealMonthlyBillingSeederTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /** The sheet's July totals. */
    private const SHEET_SALARIES = 3961427;

    /** The 3,300 EUR a month the sheet quotes separately, at its rate. */
    private const EUR_COMPONENT_PKR = 3300 * 304;

    private const SHEET_EXPENSES = 2308826;

    /** The two instalments coming off the advances in this month. */
    private const SHEET_RECOVERIES = 130000;

    private const SHEET_TOTAL = self::SHEET_SALARIES + self::EUR_COMPONENT_PKR
        + self::SHEET_EXPENSES - self::SHEET_RECOVERIES;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'seeder@test.local'));
        $this->setCurrentTenant();

        $this->seed(TransactionTypeSeeder::class);
        $this->seed(RealEmployeeSeeder::class);
        $this->seed(RealMonthlyBillingSeeder::class);
    }

    public function test_it_bills_the_month_the_sheet_billed(): void
    {
        $run = BillingRun::firstOrFail();
        $breakdown = app(\App\Modules\Billing\Services\MonthlyBillingService::class)->breakdown($run);

        $this->assertSame(
            (float) (self::SHEET_SALARIES + self::EUR_COMPONENT_PKR),
            $breakdown['salary_total'],
            'the whole roster at the packages on the sheet',
        );

        $this->assertSame((float) self::SHEET_EXPENSES, $breakdown['expense_total']);
        $this->assertSame(-((float) self::SHEET_RECOVERIES), $breakdown['credit_total']);
        $this->assertSame((float) self::SHEET_TOTAL, $breakdown['subtotal']);
    }

    public function test_it_leaves_a_draft_invoice_to_review(): void
    {
        $run = BillingRun::firstOrFail();

        $this->assertNotNull($run->invoice);
        $this->assertSame(Invoice::STATUS_DRAFT, $run->invoice->status);
        $this->assertSame('EUR', $run->currency);
        $this->assertSame(round(self::SHEET_TOTAL / 304, 2), $run->totalInClientCurrency());
    }

    public function test_the_advances_are_recovering(): void
    {
        $advances = Advance::with('employee.user')->get();

        $this->assertCount(2, $advances);

        foreach ($advances as $advance) {
            $this->assertSame(Advance::STATUS_ACTIVE, $advance->status);
            $this->assertSame(1500000.0, (float) $advance->total_amount);

            // One month in, so one instalment is off and the rest is outstanding.
            $this->assertSame((float) $advance->monthly_instalment, $advance->recoveredAmount());
            $this->assertSame(1500000.0 - (float) $advance->monthly_instalment, $advance->remainingAmount());

            // Dated the month payroll took it, not the day it was seeded.
            $this->assertSame(
                $advance->started_on->endOfMonth()->toDateString(),
                $advance->recoveries()->first()->recovered_on->toDateString(),
            );
        }
    }

    public function test_the_advance_instalment_reaches_the_payslip(): void
    {
        $payslip = Payslip::where('advances', '>', 0)->first();

        $this->assertNotNull($payslip, 'somebody is repaying an advance');
        $this->assertContains((float) $payslip->advances, [60000.0, 70000.0]);
    }

    /**
     * The seeder is the sheet's word on these figures, so a second run has to
     * restore them. Two things stand in the way of that and both are handled in
     * payslips(): an unchanged payslip is not dirty, so Eloquent runs no UPDATE and
     * the recalculation hooked to `updating` never fires; and a figure already on a
     * payslip is treated by payroll as a deliberate override that outranks the
     * employee's settings.
     */
    public function test_re_running_it_restores_a_package_edited_underneath(): void
    {
        $user = \App\Modules\Core\Models\User::query()->acrossCompanies()
            ->where('email', 'ufarooq@erbium.ch')->firstOrFail();
        $employee = Employee::where('user_id', $user->id)->firstOrFail();

        // His package is the interesting one: a PKR salary plus the EUR component.
        $payslip = Payslip::where('employee_id', $employee->id)->firstOrFail();
        $asSeeded = (float) $payslip->total_earnings;

        $this->assertSame(1516700.0, $asSeeded, '513,500 in PKR plus 3,300 EUR at 304');

        // Through the model, so the payslip is left in a state the application can
        // actually reach: a figure overridden by hand, with the totals recomputed
        // around it.
        $payslip->update(['petrol_allowance' => 5000]);

        $this->assertSame($asSeeded - 80800 + 5000, (float) $payslip->fresh()->total_earnings, 'the override took');

        $this->seed(RealMonthlyBillingSeeder::class);

        $this->assertSame(80800.0, (float) $payslip->fresh()->petrol_allowance, 'the override is gone');
        $this->assertSame($asSeeded, (float) $payslip->fresh()->total_earnings);
    }

    public function test_running_it_twice_does_not_double_anything(): void
    {
        // Re-running a seeder is normal — after adding a person, or correcting a
        // figure — and the month must not be billed twice for it.
        $employees = Employee::count();

        // Timestamps are stored to the second, so the second run has to land in a
        // later one for "was it re-saved" to be answerable at all.
        $this->travel(1)->minutes();

        $this->seed(RealMonthlyBillingSeeder::class);

        $this->assertSame($employees, Employee::count());
        $this->assertSame(1, BillingRun::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(2, Advance::count());

        foreach (Advance::all() as $advance) {
            $this->assertSame((float) $advance->monthly_instalment, $advance->recoveredAmount());
        }

        $run = BillingRun::firstOrFail();
        $this->assertSame((float) self::SHEET_TOTAL, round((float) $run->invoice->total, 2));

        // Every payslip is genuinely re-saved, not skipped as unchanged — that is
        // what lets a revised package reach a payslip that already exists, since the
        // recalculation is hooked to `updating` and Eloquent will not update a model
        // it considers clean.
        foreach (Payslip::all() as $payslip) {
            $this->assertTrue(
                $payslip->updated_at->greaterThan($payslip->created_at),
                "payslip #{$payslip->id} was re-saved",
            );
        }
    }
}
