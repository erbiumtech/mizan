<?php

namespace App\Modules\Leave\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveDay;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Support\Carbon;

/**
 * What somebody has left, computed every time.
 *
 * `opening + carried_in + accrued + adjustments − taken`, where `taken` is a sum
 * over leave_days and `adjustments` is a sum over rows. Nothing here is stored: a
 * balance column drifts against the days actually consumed and nothing reports the
 * drift, which is the same reason an account's balance is computed from its journal
 * lines rather than kept on the account.
 *
 * The two sums are the reason the two tables exist as tables. A single
 * `adjustment_days` column could not hold a March grant and an August correction at
 * once, and a `days` total on the request could not be split across the two months
 * a request spans.
 */
class LeaveBalance
{
    public function __construct(private readonly LeaveYear $year) {}

    /**
     * The full picture for one employee, one type, in the leave year covering a date.
     *
     * Returns null for a type that is not counted down — `unlimited` sick leave and
     * `none` unpaid leave have no balance, and answering 0 would read as "none left"
     * rather than "not rationed". Callers must distinguish those, which is why this
     * is null rather than a zeroed breakdown.
     */
    public function for(Employee $employee, LeaveType $type, string|Carbon|null $date = null): ?LeaveBalanceBreakdown
    {
        if (! $type->isCounted()) {
            return null;
        }

        $date = $date ? Carbon::parse($date) : now();

        $entitlement = LeaveEntitlement::query()
            ->where('employee_id', $employee->getKey())
            ->where('leave_type_id', $type->getKey())
            ->covering($date)
            ->first();

        // No entitlement generated yet is a real state — a type added mid-year, or
        // the year not yet opened — and it means nothing has been credited. It is
        // NOT the same as a zero balance after taking days, so the breakdown carries
        // the window it used and the caller can tell them apart.
        [$start, $end] = $entitlement
            ? [$entitlement->leave_year_start, $entitlement->leave_year_end]
            : $this->year->windowFor($employee, $date);

        return new LeaveBalanceBreakdown(
            entitlement: $entitlement,
            windowStart: Carbon::parse($start),
            windowEnd: Carbon::parse($end),
            opening: (float) ($entitlement->opening_days ?? 0),
            carriedIn: (float) ($entitlement->carried_in_days ?? 0),
            accrued: (float) ($entitlement->accrued_days ?? 0),
            adjustments: (float) ($entitlement?->adjustments()->sum('days') ?? 0),
            taken: $this->taken($employee, $type, $start, $end),
            pending: $this->pending($employee, $type, $start, $end),
        );
    }

    /** The shorthand every call site actually wants; null for an uncounted type. */
    public function remaining(Employee $employee, LeaveType $type, string|Carbon|null $date = null): ?float
    {
        return $this->for($employee, $type, $date)?->remaining();
    }

    /**
     * Days consumed by APPROVED requests inside the window.
     *
     * Approved only, and summed on `portion` rather than counted, so a half day
     * costs half a day. A pending request has consumed nothing; a cancelled one has
     * given its days back.
     */
    public function taken(Employee $employee, LeaveType $type, string|Carbon $from, string|Carbon $to): float
    {
        return (float) $this->daysQuery($employee, $type, $from, $to)
            ->whereHas('request', fn ($request) => $request->approved())
            ->sum('portion');
    }

    /**
     * Days a pending request would consume if approved.
     *
     * Shown beside the balance rather than deducted from it. Deducting would make a
     * balance that moves when somebody *asks*, so two people asking for the same last
     * day would each see it as gone; showing it lets an approver see that the balance
     * is about to be spent without pretending it already has been.
     *
     * Read from `leave_requests.days`, NOT from leave_days — because a pending request
     * has no leave_days at all. They are generated exactly once, at approval, from the
     * calendar and the sandwich setting as they stand then, which is the invariant that
     * keeps a settled month from being restated. Summing day rows here would therefore
     * always return zero, which is how this was found.
     *
     * One imprecision, stated rather than hidden: a pending request straddling two
     * leave years is counted whole against the year its range overlaps, since without
     * day rows there is nothing to split on. It is advisory either way — an approver
     * sees the real cost per year the moment they approve it.
     */
    public function pending(Employee $employee, LeaveType $type, string|Carbon $from, string|Carbon $to): float
    {
        return (float) LeaveRequest::query()
            ->where('employee_id', $employee->getKey())
            ->where('leave_type_id', $type->getKey())
            ->where('status', LeaveRequest::STATUS_PENDING)
            ->overlapping(
                Carbon::parse($from)->toDateString(),
                Carbon::parse($to)->toDateString(),
            )
            ->sum('days');
    }

    private function daysQuery(Employee $employee, LeaveType $type, string|Carbon $from, string|Carbon $to)
    {
        return LeaveDay::query()
            ->whereBetween('date', [
                Carbon::parse($from)->toDateString(),
                Carbon::parse($to)->toDateString(),
            ])
            ->whereHas('request', fn ($request) => $request
                ->where('employee_id', $employee->getKey())
                ->where('leave_type_id', $type->getKey()));
    }
}
