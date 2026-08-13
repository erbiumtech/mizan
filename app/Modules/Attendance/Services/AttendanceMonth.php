<?php

namespace App\Modules\Attendance\Services;

/**
 * One employee's month, as the figures payroll would need.
 *
 * A value object rather than an array because of `unknownDays`: every consumer has to
 * decide what to do about days nobody answered for, and an array lets a caller read
 * `paid_days` without ever noticing the question. Here it is a named field they have
 * to skip past deliberately.
 */
class AttendanceMonth
{
    public function __construct(
        public readonly int $year,
        public readonly int $month,
        /** Working days the pattern expected, holidays already removed. */
        public readonly int $expectedDays,
        public readonly float $workedDays,
        public readonly float $absentDays,
        public readonly float $leaveDays,
        /** Days nobody has answered for. NOT absences. */
        public readonly int $unknownDays,
        public readonly int $overtimeMinutes,
    ) {}

    /**
     * Whether this month can be trusted to reduce somebody's pay.
     *
     * False while any day is unknown. Phase 3 must not pro-rate on a partly-filled
     * month: the arithmetic would be indistinguishable from a month where those days
     * were genuinely worked, and the employee is the one who loses.
     */
    public function isComplete(): bool
    {
        return $this->unknownDays === 0;
    }

    /**
     * Days of loss of pay: unpaid absence, and nothing else.
     *
     * Unknown days are excluded, which is the whole point. Leave is excluded too — a
     * paid leave day costs the employee nothing, and an *unpaid* leave day reaches
     * payroll through the leave module's own lop_days rather than through here, so
     * counting it in both places would dock it twice.
     */
    public function lossOfPayDays(): float
    {
        return $this->absentDays;
    }

    /**
     * Working days actually paid.
     *
     * `expected − absent`, with unknown days counted as paid. That is deliberately the
     * generous reading: a day nobody recorded is a day nobody can prove was missed,
     * and the alternative silently docks pay for a clerk's omission.
     */
    public function paidDays(): float
    {
        return max(0, $this->expectedDays - $this->absentDays);
    }

    public function overtimeHours(): float
    {
        return round($this->overtimeMinutes / 60, 2);
    }

    /** A short sentence for a payroll clerk about to trust these figures. */
    public function completenessNote(): ?string
    {
        if ($this->isComplete()) {
            return null;
        }

        return "{$this->unknownDays} day(s) of this month have not been marked. "
            .'They are counted as worked, because a day nobody recorded is not a day anybody missed.';
    }
}
