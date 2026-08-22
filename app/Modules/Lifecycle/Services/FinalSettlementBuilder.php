<?php

namespace App\Modules\Lifecycle\Services;

use App\Modules\Advances\Services\AdvanceService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalance;
use App\Modules\Lifecycle\Models\FinalSettlement;
use App\Modules\Lifecycle\Models\IssuedAsset;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Gathering what somebody is owed on leaving.
 *
 * **Every figure here is a proposal.** Nothing posts, nothing is paid, and nothing is
 * final until a person approves it — paying goes through the existing payslip or
 * payment path. §4.6 is explicit, and the reason is the ledger: a second money path
 * writing its own entries is how a ledger stops reconciling.
 *
 * Every source is guarded, and each one that is missing contributes zero rather than
 * failing. A company with no `leave` module has no encashment; one with no `advances`
 * has no balance to recover. A settlement built at such a company is a smaller
 * document, not a broken one.
 */
class FinalSettlementBuilder
{
    public function __construct(private readonly LeaveBalance $balance) {}

    /**
     * Build (or rebuild) the draft settlement for an employee.
     *
     * Refuses once approved. An approved settlement is a figure somebody committed to,
     * and rebuilding it from today's data would silently move what was agreed —
     * particularly since an advance balance changes as recoveries post.
     */
    public function build(Employee $employee, string|Carbon|null $leftOn = null): FinalSettlement
    {
        $leftOn = Carbon::parse($leftOn ?? $employee->left_on ?? now());

        $settlement = FinalSettlement::firstOrNew(['employee_id' => $employee->getKey()]);

        if ($settlement->exists && ! $settlement->isDraft()) {
            throw new InvalidArgumentException(
                'This settlement has already been approved. Reopen it before rebuilding, or the agreed figure would move underneath it.'
            );
        }

        $encashment = $this->leaveEncashment($employee, $leftOn);

        $settlement->fill([
            'left_on' => $leftOn->toDateString(),
            'leave_encashment_days' => $encashment['days'],
            'leave_encashment_amount' => $encashment['amount'],
            'gratuity_amount' => $this->gratuity($employee, $leftOn),
            'outstanding_advance' => $this->outstandingAdvance($employee),
            'unreturned_asset_value' => $this->unreturnedAssets($employee),
        ]);

        // Notice recovery and other deductions are deliberately NOT computed. Whether
        // notice was served, and what to do about it, is a judgement somebody makes —
        // and a number this class invented would look like a fact.
        $settlement->net_amount = $settlement->computedNet();

        $settlement->save();

        return $settlement;
    }

    /**
     * Encashable leave: the current year's unused balance, on types marked encashable.
     *
     * Only on separation. A year-end lapse pays nobody, which §4.1 is explicit about not
     * conflating with this — they are two different moments and only one of them is
     * money.
     *
     * @return array{days: float, amount: float}
     */
    public function leaveEncashment(Employee $employee, Carbon $leftOn): array
    {
        if (! modules()->enabled('leave')) {
            return ['days' => 0.0, 'amount' => 0.0];
        }

        $days = 0.0;

        foreach (LeaveType::query()->where('is_encashable', true)->where('is_active', true)->get() as $type) {
            $remaining = $this->balance->remaining($employee, $type, $leftOn);

            // Only a positive balance. Somebody who over-drew their leave is not
            // charged for it here: recovering over-taken paid leave is a decision, and
            // `other_deductions` is where a person records having made it.
            if ($remaining !== null && $remaining > 0) {
                $days += $remaining;
            }
        }

        $daily = $this->dailyRate($employee, $leftOn);

        return [
            'days' => round($days, 1),
            'amount' => $daily === null ? 0.0 : round($days * $daily, 2),
        ];
    }

    /**
     * Gratuity: one month's wage per completed year of continuous service.
     *
     * The conventional Pakistani formula, and a DEFAULT rather than a statement of law —
     * entitlement and formula vary by establishment and province, which is why the
     * multiplier is a setting. §6 takes the same position on every statutory figure.
     *
     * Continuous service runs from the first job-history row rather than
     * `date_of_joining`, because somebody re-employed after a break has two spans and
     * only the current one counts. Falls back to the joining date for the employees who
     * predate job history.
     */
    public function gratuity(Employee $employee, Carbon $leftOn): float
    {
        $years = $this->completedYearsOfService($employee, $leftOn);

        $minimum = (int) setting('statutory.gratuity.minimum_years', 1);

        if ($years < $minimum) {
            return 0.0;
        }

        $setting = $this->currentPackage($employee, $leftOn);

        if (! $setting) {
            return 0.0;
        }

        $monthsPerYear = (float) setting('statutory.gratuity.months_per_year', 1.0);

        return round((float) $setting->basic_wage * $monthsPerYear * $years, 2);
    }

    /** Whole years between starting and leaving, from job history where there is any. */
    public function completedYearsOfService(Employee $employee, Carbon $leftOn): int
    {
        $started = $employee->jobHistory()->orderBy('effective_from')->value('effective_from')
            ?? $employee->date_of_joining;

        if (! $started) {
            return 0;
        }

        return max(0, (int) Carbon::parse($started)->startOfDay()->diffInYears($leftOn->copy()->startOfDay()));
    }

    /**
     * What is still owed on any advance. Zero without the module.
     *
     * Summed from `Advance::remainingAmount()` per active advance rather than from a
     * stored balance, for the reason every balance in this application is computed:
     * the recoveries are the record, and a total that drifted from them would be a
     * number nobody could explain at exactly the moment somebody is leaving.
     */
    public function outstandingAdvance(Employee $employee): float
    {
        if (! modules()->enabled('advances')) {
            return 0.0;
        }

        return round(
            app(AdvanceService::class)
                ->activeFor($employee->getKey())
                ->sum(fn ($advance): float => $advance->remainingAmount()),
            2,
        );
    }

    /** What has not come back, valued at whatever the register says it is worth. */
    public function unreturnedAssets(Employee $employee): float
    {
        return round((float) IssuedAsset::query()
            ->where('employee_id', $employee->getKey())
            ->outstanding()
            ->sum('value'), 2);
    }

    /**
     * A day's pay, for valuing encashed leave.
     *
     * Basic wage over a conventional 26 working days. Deliberately a fixed divisor
     * rather than the pro-rating one: encashment is a lump sum against a package, not a
     * month's attendance, and borrowing the attendance divisor would make the payout
     * depend on which month somebody happened to leave in.
     */
    private function dailyRate(Employee $employee, Carbon $leftOn): ?float
    {
        $setting = $this->currentPackage($employee, $leftOn);

        if (! $setting || (float) $setting->basic_wage <= 0) {
            return null;
        }

        $divisor = max(1, (int) setting('statutory.encashment_divisor', 26));

        return round((float) $setting->basic_wage / $divisor, 2);
    }

    private function currentPackage(Employee $employee, Carbon $leftOn): ?EmployeeSetting
    {
        return EmployeeSetting::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('start_date', '<=', $leftOn->toDateString())
            ->orderByDesc('start_date')
            ->first()
            ?? EmployeeSetting::query()
                ->where('employee_id', $employee->getKey())
                ->orderByDesc('start_date')
                ->first();
    }
}
