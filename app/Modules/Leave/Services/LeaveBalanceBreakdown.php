<?php

namespace App\Modules\Leave\Services;

use App\Modules\Leave\Models\LeaveEntitlement;
use Illuminate\Support\Carbon;

/**
 * A balance and every term that produced it.
 *
 * The terms are carried rather than collapsed into one number because "why is my
 * balance 4.5" is the question this module will be asked most often, and a bare
 * total cannot answer it. Each field here is one line of the arithmetic a company's
 * HR will be asked to defend.
 */
class LeaveBalanceBreakdown
{
    public function __construct(
        /** Null when no entitlement has been generated for this window yet. */
        public readonly ?LeaveEntitlement $entitlement,
        public readonly Carbon $windowStart,
        public readonly Carbon $windowEnd,
        public readonly float $opening,
        public readonly float $carriedIn,
        public readonly float $accrued,
        public readonly float $adjustments,
        public readonly float $taken,
        public readonly float $pending,
    ) {}

    /** Everything credited, before anything taken. */
    public function credited(): float
    {
        return round($this->opening + $this->carriedIn + $this->accrued + $this->adjustments, 1);
    }

    /** The number an employee means by "my balance". */
    public function remaining(): float
    {
        return round($this->credited() - $this->taken, 1);
    }

    /**
     * What would be left if every pending request were approved.
     *
     * Worth showing an approver deciding the third request against the last two
     * days: the balance says two, and this says what the queue already promises.
     */
    public function remainingIfPendingApproved(): float
    {
        return round($this->remaining() - $this->pending, 1);
    }

    /** Whether an entitlement exists at all, as against existing and being spent. */
    public function isUngenerated(): bool
    {
        return $this->entitlement === null;
    }

    public function covers(string|Carbon $date): bool
    {
        $date = Carbon::parse($date)->startOfDay();

        return $date->gte($this->windowStart->copy()->startOfDay())
            && $date->lte($this->windowEnd->copy()->startOfDay());
    }
}
