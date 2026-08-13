<?php

namespace App\Modules\Payroll\Services;

/**
 * A month's overtime, and every term that produced it.
 *
 * The rate and the multiplier travel with the amount because the amount alone cannot
 * be reproduced later: the package changes, the pattern changes, and the settings
 * change. A payslip that records only "overtime: 12,500" cannot be re-derived next
 * year, and an employee asking why is owed an answer.
 */
class OvertimePay
{
    public function __construct(
        public readonly float $hourlyRate,
        public readonly float $multiplier,
        public readonly int $minutes,
        public readonly float $amount,
    ) {}

    public function hours(): float
    {
        return round($this->minutes / 60, 2);
    }

    public function explain(): string
    {
        return sprintf(
            '%s hours at %s × %s = %s',
            $this->hours(),
            number_format($this->hourlyRate, 2),
            rtrim(rtrim(number_format($this->multiplier, 2), '0'), '.'),
            number_format($this->amount, 2),
        );
    }
}
