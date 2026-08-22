<?php

namespace App\Modules\ConstructionCosting\Support;

/**
 * §4.2's statement, computed — `docs/construction-management-plan.md` §4.2.
 *
 * The plan writes the arithmetic out as a block and this is that block, one property per line of it:
 *
 * ```
 * GL cost for the period
 *   less   GL cost carrying no job        [UNALLOCATED — shown, never spread]
 *   plus   job cost still awaiting the GL [the reconciling item]
 * = Expected job-cost total
 *   vs     Σ cost entries where gl_treatment != 'memo'
 * Difference                              must be 0.00
 * ```
 *
 * **"Shown, never spread"** is the instruction that shapes this class. Unallocated GL cost is its own line and is
 * subtracted whole; it is never apportioned across jobs to make the difference disappear. A cost journalled straight to
 * `5020` from the Accounting panel belongs to no job, and pretending otherwise would put money on jobs nobody charged it
 * to and balance a report that ought to be complaining.
 *
 * **And the causes are part of the result rather than a separate call.** §4.2: the drill-down by cause "is what makes it
 * a tool rather than a number". A difference with no causes attached is a number somebody screenshots and argues about.
 */
readonly class ReconciliationResult
{
    /**
     * @param  array<int, ReconciliationCause>  $causes
     */
    public function __construct(
        public string $periodStart,
        public float $glCost,
        public float $unallocatedGlCost,
        public float $pendingJobCost,
        public float $expectedJobCost,
        public float $jobCost,
        public float $difference,
        public array $causes,
        public ?string $scopeWarning = null,
    ) {}

    public function isBalanced(): bool
    {
        return abs($this->difference) < 0.01;
    }

    /**
     * Causes that carry a figure, largest first.
     *
     * @return array<int, ReconciliationCause>
     */
    public function significantCauses(): array
    {
        $causes = array_values(array_filter($this->causes, fn (ReconciliationCause $c): bool => $c->isSignificant()));

        usort($causes, fn (ReconciliationCause $a, ReconciliationCause $b): int => abs($b->amount) <=> abs($a->amount));

        return $causes;
    }

    /** @return array<int, array<string, mixed>> */
    public function causesForStorage(): array
    {
        return array_map(fn (ReconciliationCause $c): array => $c->toArray(), $this->causes);
    }

    /**
     * The statement as a sentence.
     *
     * A balanced run says so in one line. An unbalanced one leads with the figure and then the largest cause, because
     * "the difference is 412,900" is a fact somebody can do nothing with and "412,900, of which 412,900 is burden
     * charged with no absorption account" is a fact they can act on this morning.
     */
    public function describe(): string
    {
        if ($this->scopeWarning !== null) {
            return $this->scopeWarning;
        }

        if ($this->isBalanced()) {
            return 'Balanced. GL cost '.number_format($this->glCost, 2).' reconciles to job cost '
                .number_format($this->jobCost, 2).'.';
        }

        $sentence = 'Difference '.number_format($this->difference, 2).'. Expected job cost '
            .number_format($this->expectedJobCost, 2).' against '.number_format($this->jobCost, 2).' recorded.';

        $largest = $this->significantCauses()[0] ?? null;

        return $largest === null
            ? $sentence.' No cause accounts for it, which usually means a GL cost account was posted to outside this '
                .'module and outside the control-account list.'
            : $sentence.' Largest cause: '.$largest->describe();
    }
}
