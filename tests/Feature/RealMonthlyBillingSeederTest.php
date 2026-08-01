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
 * totals rather than against what the code happens to produce. Muzafar Ali is not
 * seeded — his package is in EUR — and the sheet leaves him out of these totals
 * too, so they match exactly.
 */
class RealMonthlyBillingSeederTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /** The sheet's July totals. */
    private const SHEET_SALARIES = 3961427;

    private const SHEET_EXPENSES = 2308826;

    /** The two instalments coming off the advances in this month. */
    private const SHEET_RECOVERIES = 130000;

    private const SHEET_TOTAL = self::SHEET_SALARIES + self::SHEET_EXPENSES - self::SHEET_RECOVERIES;

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
            (float) self::SHEET_SALARIES,
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

    public function test_running_it_twice_does_not_double_anything(): void
    {
        // Re-running a seeder is normal — after adding a person, or correcting a
        // figure — and the month must not be billed twice for it.
        $employees = Employee::count();

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
    }
}
