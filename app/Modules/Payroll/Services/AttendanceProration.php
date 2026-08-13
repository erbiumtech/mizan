<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Support\PayrollMonth;

/**
 * Whether pay is reduced for unpaid absence, and by how much.
 *
 * The riskiest class in this application, so it is written to refuse rather than
 * guess. `basisFor()` returns null — meaning "pay the full month" — in every case
 * where the answer is not certain, and there are five of them.
 *
 * **Where this is applied matters as much as what it computes.** Scaling
 * `basic_wage`, `total_earnings` and `net_salary` after the calculation is the obvious
 * shortcut, and it does not merely mis-state the payslip: the payroll journal entry
 * stops balancing and payslip *creation* throws mid-run, because PayrollPostingService
 * books debits from the earning figures and credits from the deductions and net
 * payable, and scaling one side leaves the other where it was. That was measured, not
 * assumed — see PayslipCalculationSeamTest, which exists to catch exactly that
 * shortcut. Pro-rating therefore happens inside PayslipService::calculateByParams(),
 * on the amounts the posting later reads.
 */
class AttendanceProration
{
    public const DIVISOR_WORKING_DAYS = 'working_days';

    public const DIVISOR_CALENDAR_DAYS = 'calendar_days';

    public const DIVISOR_FIXED_26 = 'fixed_26';

    public const DIVISOR_FIXED_30 = 'fixed_30';

    /** @var array<int, string> */
    public const DIVISORS = [
        self::DIVISOR_WORKING_DAYS,
        self::DIVISOR_CALENDAR_DAYS,
        self::DIVISOR_FIXED_26,
        self::DIVISOR_FIXED_30,
    ];

    /**
     * The basis for one payslip, or null to pay the month in full.
     *
     * Null in all of these, each for its own reason:
     *
     *  1. **The company has not switched pro-rating on.** The default, and the whole
     *     safety of phase 3.
     *  2. **`total_working_days` is 0.** That means "not known", not "no working
     *     days" — it is what MonthlyPayrollService raises for a month nobody has
     *     entered attendance for, and dividing by it pays nobody.
     *  3. **`lop_days` is 0.** Nothing was lost, so there is nothing to reduce.
     *     Recording a divisor here would be noise on a payslip that was paid in full.
     *  4. **The divisor works out at zero or less.** Defensive, and it is the
     *     division this class exists to be careful about.
     *  5. **Loss of pay exceeds the month.** Somebody absent for more days than the
     *     month has is a data error, not a person who owes the company money, and a
     *     negative factor would invert every earning on the payslip.
     */
    public function basisFor(
        ?float $totalWorkingDays,
        ?float $lopDays,
        string $month,
        ?FiscalYear $fiscalYear,
    ): ?ProrationBasis {
        if (! setting('payroll.prorate_on_attendance')) {
            return null;
        }

        $totalWorkingDays = (float) ($totalWorkingDays ?? 0);
        $lopDays = (float) ($lopDays ?? 0);

        if ($totalWorkingDays <= 0 || $lopDays <= 0) {
            return null;
        }

        $divisorName = $this->divisorName();
        $basisDays = $this->basisDays($divisorName, $totalWorkingDays, $month, $fiscalYear);

        if ($basisDays <= 0) {
            return null;
        }

        $paidDays = $basisDays - $lopDays;

        if ($paidDays <= 0) {
            // Deliberately not clamped to zero and applied. A month that reads as
            // wholly unpaid is almost always a broken import, and paying nothing on
            // the strength of it is the one mistake there is no undoing after the
            // bank file has gone out. Pay in full and let somebody look.
            return null;
        }

        return new ProrationBasis(
            divisor: $divisorName,
            basisDays: round($basisDays, 1),
            paidDays: round($paidDays, 1),
        );
    }

    /**
     * The basis a payslip already recorded, so a recalculation reproduces it exactly.
     *
     * This is what stops a settled month moving. Payslip::booted() re-runs the whole
     * calculation on every update — a clerk correcting a phone number re-saves the
     * payslip — so reading today's setting would restate last March the moment
     * somebody changed the divisor. Once a payslip carries a divisor, that is the one
     * it keeps.
     */
    public function recordedBasis(?string $divisor, ?float $basisDays, ?float $lopDays): ?ProrationBasis
    {
        if (! $divisor || ! $basisDays || $basisDays <= 0) {
            return null;
        }

        $paidDays = $basisDays - (float) ($lopDays ?? 0);

        return $paidDays > 0
            ? new ProrationBasis($divisor, round($basisDays, 1), round($paidDays, 1))
            : null;
    }

    private function divisorName(): string
    {
        $divisor = (string) setting('payroll.proration_divisor', self::DIVISOR_WORKING_DAYS);

        // An unrecognised divisor is a typo in .env or a hand-edited settings row.
        // Falling back to the documented default is safer than guessing, and safer
        // than throwing on a payroll run at 2am.
        return in_array($divisor, self::DIVISORS, true) ? $divisor : self::DIVISOR_WORKING_DAYS;
    }

    private function basisDays(string $divisor, float $totalWorkingDays, string $month, ?FiscalYear $fiscalYear): float
    {
        return match ($divisor) {
            self::DIVISOR_FIXED_26 => 26.0,
            self::DIVISOR_FIXED_30 => 30.0,
            self::DIVISOR_CALENDAR_DAYS => $fiscalYear
                ? (float) PayrollMonth::firstDay($month, $fiscalYear)->daysInMonth
                : 30.0,
            // The number attendance can actually produce, and the only one that is
            // right for a month with 21 working days rather than 22.
            default => $totalWorkingDays,
        };
    }
}
