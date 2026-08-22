<?php

namespace App\Modules\Payroll\Services;

/**
 * How much of a month somebody is paid for, and the rule that produced it.
 *
 * The rule travels with the number because the number alone cannot be defended. "You
 * were paid 19/22" is answerable; "your pay was multiplied by 0.8636" is not.
 */
class ProrationBasis
{
    public function __construct(
        /** One of AttendanceProration::DIVISORS. */
        public readonly string $divisor,
        /** What was divided by — 22 working days, or a fixed 26, or the calendar month. */
        public readonly float $basisDays,
        /** What is being paid for. Always > 0; the service refuses to build this otherwise. */
        public readonly float $paidDays,
    ) {}

    /**
     * The multiplier applied to each pro-rating component.
     *
     * Capped at 1.0. Without the cap, a `fixed_30` divisor against a 22-working-day
     * month with two days lost would produce 28/30 — but a `fixed_26` basis with only
     * one day lost gives 25/26, and an employee with *no* loss would already have
     * returned null. The cap is what guarantees pro-rating can only ever reduce pay,
     * never quietly increase it, whatever combination of divisor and month arrives.
     */
    public function factor(): float
    {
        return min(1.0, $this->paidDays / $this->basisDays);
    }

    /** Applied to one amount, rounded the way every other payroll figure is. */
    public function apply(float $amount): float
    {
        return round($amount * $this->factor(), 2);
    }

    /** What a payslip should say about why it is short. */
    public function explain(): string
    {
        $format = fn (float $days): string => rtrim(rtrim(number_format($days, 1), '0'), '.');

        return "Paid for {$format($this->paidDays)} of {$format($this->basisDays)} days.";
    }
}
