<?php

namespace App\Modules\ConstructionCosting\Support;

/**
 * One reason the two ledgers might disagree — `docs/construction-management-plan.md` §4.2.
 *
 * §4.2 names seven and this class is one of them. Each carries a count, an amount, a sentence somebody can act on, and
 * **whether it should be rendered when empty**.
 *
 * That last flag is the plan's own instruction on one cause in particular: "**unallocated purchase invoices, rendered
 * even when empty**". §5 calls an unallocated purchase invoice "the single most likely silent failure in the module" —
 * the accounts are perfectly correct and the job is under-costed — so a section that vanished when the list was empty
 * would be indistinguishable from a section nobody had built. A zero somebody has seen is worth more than a blank.
 *
 * **Rounding is its own cause and is deliberately last.** §4.2: "rounding, isolated so it cannot be used to explain
 * anything else." A residual folded into any other line is a residual that grows.
 */
readonly class ReconciliationCause
{
    public function __construct(
        public string $key,
        public string $label,
        public int $count,
        public float $amount,
        public string $explanation,
        public bool $renderWhenEmpty = false,
        /** @var array<int, array<string, mixed>> */
        public array $rows = [],
    ) {}

    /** Whether this cause has anything to say. */
    public function isSignificant(): bool
    {
        return $this->count > 0 || abs($this->amount) >= 0.01;
    }

    /** Whether the report should show it at all. */
    public function shouldRender(): bool
    {
        return $this->isSignificant() || $this->renderWhenEmpty;
    }

    public function describe(): string
    {
        if (! $this->isSignificant()) {
            return $this->label.': none. '.$this->explanation;
        }

        return $this->label.': '.$this->count.' item'.($this->count === 1 ? '' : 's')
            .' worth '.number_format($this->amount, 2).'. '.$this->explanation;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'count' => $this->count,
            'amount' => $this->amount,
            'explanation' => $this->explanation,
            'render_when_empty' => $this->renderWhenEmpty,
            'rows' => $this->rows,
        ];
    }
}
