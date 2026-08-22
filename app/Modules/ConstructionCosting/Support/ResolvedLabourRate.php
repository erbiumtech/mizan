<?php

namespace App\Modules\ConstructionCosting\Support;

/**
 * What an hour costs, as at a date, and where each figure came from — `docs/construction-management-plan.md` §7.2.
 *
 * Three values and their provenance, because §7.1 has the labour record snapshot `cost_rate_per_hour` and
 * `burden_percent` at approval: the snapshot is only defensible if somebody can later ask *which row* it came from,
 * and a bare float cannot answer that.
 *
 * **The three fields resolve independently.** `overtime_multiplier` and `burden_percent` are nullable on
 * `construction_labour_rates` precisely so a row can revise the rate without restating terms the company set once —
 * so a job's site-allowance row can carry the rate while the company default still supplies the overtime multiplier.
 * `rateId` therefore names where the *rate* came from, and the other two may have come from further down the ladder
 * or from config; `overtimeFromRate` and `burdenFromRate` say which.
 */
final readonly class ResolvedLabourRate
{
    /**
     * @param  float  $costRatePerHour  what a normal hour costs
     * @param  float  $overtimeMultiplier  what an overtime hour costs relative to a normal one
     * @param  float  $burdenPercent  statutory and welfare cost on top, as a percentage
     * @param  int  $rateId  the `construction_labour_rates` row the rate itself came from
     * @param  int  $tier  which tier of §7.2's ladder that row sits on — see LabourRate::TIER_*
     * @param  bool  $overtimeFromRate  false when the multiplier fell through to the shipped default
     * @param  bool  $burdenFromRate  false when the burden fell through to the shipped default
     */
    public function __construct(
        public float $costRatePerHour,
        public float $overtimeMultiplier,
        public float $burdenPercent,
        public int $rateId,
        public int $tier,
        public bool $overtimeFromRate = true,
        public bool $burdenFromRate = true,
    ) {}

    /** The cost of a span of work, in minutes — minutes because that is what a site sheet records (§7.1). */
    public function costOf(int $normalMinutes, int $overtimeMinutes = 0): float
    {
        $normal = ($normalMinutes / 60) * $this->costRatePerHour;
        $overtime = ($overtimeMinutes / 60) * $this->costRatePerHour * $this->overtimeMultiplier;

        return round($normal + $overtime, 2);
    }

    /** The burden on a labour cost, which §7.3 requires to be its own entry against the same cost code. */
    public function burdenOn(float $labourCost): float
    {
        return round($labourCost * $this->burdenPercent / 100, 2);
    }
}
