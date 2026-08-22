<?php

namespace App\Modules\ConstructionCosting\Support;

use App\Modules\ConstructionCosting\Models\CostBatch;

/**
 * Opening a month, as one answer — `docs/construction-management-plan.md` §4.5.
 *
 * Three things happen in one act and the order is the whole design: **reverse last month's accruals first, then
 * re-accrue what is still outstanding.**
 *
 * §4.5 explains why it is reverse-and-re-accrue rather than matching an accrual off against the eventual invoice:
 * "matching an accrual line-by-line to a later invoice is the same heuristic that fails for commitment relief, and an
 * accrual that fails to match sits on the balance sheet forever with nobody able to say what it is for."
 *
 * And it explains why this belongs to period **open** rather than period close: the failure mode of
 * reverse-and-re-accrue is the reversal not running, "so the accrual and the real invoice both sit in the ledger and the
 * job costs double for a month". A step attached to opening the month runs before anybody looks at the month's figures.
 * A step attached to closing it runs after everybody has.
 */
readonly class AccrualRun
{
    public function __construct(
        public string $periodStart,
        public ?CostBatch $reversalBatch,
        public int $reversedCount,
        public float $reversedTotal,
        public AccrualResult $goodsReceived,
        public AccrualResult $subcontract,
    ) {}

    public function reversedSomething(): bool
    {
        return $this->reversedCount > 0;
    }

    public function total(): float
    {
        return round($this->goodsReceived->total + $this->subcontract->total, 2);
    }

    /**
     * The whole month-open as a paragraph.
     *
     * The reversal is stated first and stated always, including when it reversed nothing — because "nothing was
     * reversed" is the sentence that distinguishes a first month from a month where the reversal silently failed to
     * find last month's accruals, and those are very different facts.
     */
    public function describe(): string
    {
        $parts = [$this->reversedSomething()
            ? 'Reversed '.$this->reversedCount.' accrual'.($this->reversedCount === 1 ? '' : 's')
                .' worth '.number_format($this->reversedTotal, 2).' from earlier periods.'
            : 'No accrual from an earlier period was outstanding, so nothing was reversed.'];

        $parts[] = $this->goodsReceived->describe();
        $parts[] = $this->subcontract->describe();

        return implode(' ', $parts);
    }
}
