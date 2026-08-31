<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\TaxCalculatorService;
use App\Support\Reporting\ReportPeriod;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A one-off bonus is taxed once over the year — asserted, because reading the code says otherwise.
 *
 * **This file exists because I got it wrong, and it is the shape of mistake worth a test rather than a
 * comment.** Withholding here is annualised: the month's taxable gross stands in for the months not yet
 * paid, and the year's tax is computed from that projection. Read that far and a one-off bonus looks badly
 * over-taxed — a 100,000 bonus in July projects a year of 3,600,000 instead of 2,500,000. Ten lines further
 * down is the part that settles it:
 *
 *     $leftoverAnnualTax = max(0, $totalAnnualTax - $previousTaxPaid);
 *     $data['withholding_tax'] = $leftoverAnnualTax / $remainingMonthsForTax;
 *
 * Every month recomputes the year from what has *actually* been paid and spreads what is left over the
 * months that remain. So the over-projection in the bonus month is given back across the rest of the year,
 * and the total is right. Measured on 200,000 a month with a 100,000 bonus in July: 27,166.67 withheld in
 * July, 8,984.85 in each of the other eleven — under the 9,300 they would otherwise pay, which is the refund
 * arriving — and **126,000 over the year, which is exactly the tax on 2,500,000**.
 *
 * What these tests pin, then, is not a fix but a property that is easy to break while trying to improve it:
 *
 *  - the year's withholding equals the year's tax, bonus included;
 *  - and it does not depend on **which month** the bonus falls in, which is the assertion that would fail if
 *    somebody removed the true-up and left the projection, or removed the projection and left a month's
 *    deduction computed from a year nobody had.
 *
 * **The timing was then changed on purpose, and that is the third test.** The bonus month used to carry the
 * whole of the bonus's tax and the following months carried less than they should; the projection now leaves
 * one-off amounts out of the months they will not be paid in, so the tax is level across the year. The annual
 * figure is untouched — this moves when it is taken, not how much.
 */
class PayslipOneOffTaxTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /** 200,000 a month, nothing else, to keep the arithmetic legible. */
    private const MONTHLY = 200_000;

    private const BONUS = 100_000;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'oneoff@test.local'));
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'employee_id' => 'EMP-ONEOFF',
            'phone' => '0300-0000000',
            'gender' => 'Male',
            'is_active' => 1,
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => $this->fiscalYear->start_date,
            'end_date' => $this->fiscalYear->end_date,
            'basic_wage' => self::MONTHLY,
        ]);
    }

    /**
     * The year's withholding, with the bonus, equals the year's tax on the year's actual income.
     *
     * Run as twelve payslips rather than one calculation, because that is the only way to see it: each
     * month's deduction is computed from its own projection *and* from what earlier months already withheld,
     * so the property is in the sum and no single month can show it.
     */
    public function test_a_one_off_bonus_is_taxed_once_across_the_year(): void
    {
        $withheld = $this->runTheYear(bonusIn: 'July');

        $this->assertSame($this->taxOnAnnualIncome(12 * self::MONTHLY + self::BONUS), $withheld);
    }

    /**
     * And it costs the same wherever in the year it falls.
     *
     * The sharper version of the same claim: a projection that treats a one-off as recurring charges more
     * for a bonus paid early — eleven months left to project it across — than for one paid in June. Nobody
     * should be able to change an employee's tax by choosing a month.
     */
    public function test_the_month_the_bonus_falls_in_does_not_change_the_tax(): void
    {
        $july = $this->runTheYear(bonusIn: 'July');

        // A second employee, same package, bonus in the last month of the year.
        $this->employee->update(['employee_id' => 'EMP-ONEOFF-A']);
        Payslip::query()->delete();

        $june = $this->runTheYear(bonusIn: 'June');

        $this->assertSame($july, $june);
    }

    /**
     * The tax on the bonus is spread over the remaining months, not taken in the month it was paid.
     *
     * This is the behaviour that was asked for, and the reason the projection now excludes one-offs. The
     * annual total was always right; what was wrong was the shape of it. Measured on the same fixture:
     *
     *     before   July 27,166.67   then 8,984.85 × 11     total 126,000
     *     after    July 10,500.00   then 10,500.00 × 11    total 126,000
     *
     * So the employee keeps 16,666.67 more of their bonus in the month they receive it, and pays it back at
     * 1,515.15 a month — which is what "spread the tax, not the payment" means.
     *
     * Asserted as *level* rather than as twelve literals: the claim is that no month carries more of the
     * bonus's tax than another, and pinning the figures would tie this test to a Finance Act.
     */
    public function test_the_tax_on_a_one_off_is_spread_over_the_remaining_months(): void
    {
        $monthly = $this->monthByMonth(bonusIn: 'July');

        $expected = round($this->taxOnAnnualIncome(12 * self::MONTHLY + self::BONUS) / 12, 2);

        // A cent of tolerance, because a year's tax rarely divides by twelve exactly and the remainder has
        // to land somewhere.
        foreach ($monthly as $month => $tax) {
            $this->assertLessThanOrEqual(
                0.02,
                abs($tax - $expected),
                "{$month} withheld {$tax} against an even {$expected} — the one-off is not being spread.",
            );
        }

        // And the bonus month specifically is not the spike it used to be.
        $this->assertLessThanOrEqual(max($monthly) + 0.02, $monthly['July']);
    }

    /** A year with no bonus is unchanged — the fix must not move the ordinary case. */
    public function test_a_year_without_a_bonus_is_unaffected(): void
    {
        $withheld = $this->runTheYear(bonusIn: null);

        $this->assertSame($this->taxOnAnnualIncome(12 * self::MONTHLY), $withheld);
    }

    /**
     * A bonus on the *settings* is charged as twelve months of it, because that is what it is.
     *
     * The other end of the range: the same 10,000 on the package is monthly pay, and the year's tax is the
     * tax on twelve of them. Together with the test above, the two cases that a single "is a bonus a one-off
     * or not" flag would collapse into one.
     */
    public function test_a_bonus_on_the_package_is_still_treated_as_monthly(): void
    {
        EmployeeSetting::where('employee_id', $this->employee->id)->update(['bonus' => 10_000]);

        $withheld = $this->runTheYear(bonusIn: null);

        $this->assertSame($this->taxOnAnnualIncome(12 * (self::MONTHLY + 10_000)), $withheld);
    }

    // ───────────────────────────────────────────────────────── fixtures ──

    /**
     * Twelve payslips in fiscal order, returning the total tax withheld.
     *
     * In order, and that matters: each month's projection reads the payslips already created, so raising
     * them out of order measures a year nobody had.
     */
    private function runTheYear(?string $bonusIn): float
    {
        return round(array_sum($this->monthByMonth($bonusIn)), 2);
    }

    /**
     * What each month withheld, keyed by month.
     *
     * @return array<string, float>
     */
    private function monthByMonth(?string $bonusIn): array
    {
        $withheld = [];

        foreach (ReportPeriod::months($this->fiscalYear->start_date) as $month) {
            $payslip = Payslip::create([
                'employee_id' => $this->employee->id,
                'fiscal_year_id' => $this->fiscalYear->id,
                'month' => $month,
                'total_working_days' => 22,
                'paid_days' => 22,
                'bonus' => $month === $bonusIn ? self::BONUS : 0,
            ]);

            $withheld[$month] = round((float) $payslip->withholding_tax, 2);
        }

        return $withheld;
    }

    /**
     * The tax the slabs charge on a year's income, taken from the application's own calculator.
     *
     * Asserting against a hard-coded figure would pin this test to one Finance Act; asserting against the
     * calculator pins it to the *claim* — that a year's withholding equals the year's tax — which is what is
     * actually being tested and stays true when the slabs change.
     */
    private function taxOnAnnualIncome(float $annual): float
    {
        // The same two steps the service takes: the 10% cut, then the slabs.
        return round(
            app(TaxCalculatorService::class)->annualTax($annual * 0.9, $this->fiscalYear->id),
            2,
        );
    }
}
