<?php

namespace App\Modules\ConstructionCosting\Support;

use App\Modules\ConstructionCosting\Models\CostBatch;

/**
 * What one accrual pass raised, and what it could not — `docs/construction-management-plan.md` §4.5.
 *
 * The `skipped` half is here for the same reason `PostingPlan` has one: an accrual that cannot be raised is a cost
 * missing from a job, and a service that returned only a count would leave somebody reading a healthy total while a
 * subcontract's work sat nowhere. §18.1's rule about a healthy figure hiding an absence applies to a raised total as
 * much as to a report.
 */
readonly class AccrualResult
{
    /**
     * @param  array<int, string>  $skipped  one sentence per thing that could not be accrued
     */
    public function __construct(
        public string $label,
        public ?CostBatch $batch,
        public int $entryCount,
        public float $total,
        public array $skipped,
    ) {}

    public static function nothing(string $label, array $skipped = []): self
    {
        return new self($label, null, 0, 0.0, $skipped);
    }

    public function raisedSomething(): bool
    {
        return $this->entryCount > 0;
    }

    public function describe(): string
    {
        $parts = [];

        $parts[] = $this->raisedSomething()
            ? $this->label.': '.number_format($this->total, 2).' across '.$this->entryCount.' entr'
                .($this->entryCount === 1 ? 'y' : 'ies').'.'
            : $this->label.': nothing outstanding.';

        foreach ($this->skipped as $sentence) {
            $parts[] = $sentence;
        }

        return implode(' ', $parts);
    }
}
