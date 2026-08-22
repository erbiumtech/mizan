<?php

namespace App\Modules\ConstructionCosting\Support;

/**
 * One gate on the way out of a month — `docs/construction-management-plan.md` §4.3.
 *
 * **`blocking` and `passed` are separate**, and the pair is what makes a close checklist honest. A check that failed and
 * blocks is a reason the month cannot close. A check that failed and does not block is something somebody should know
 * before they sign — late costs, a job with no percent-complete method — and hiding it because it is not fatal is how a
 * month gets closed on facts nobody was shown.
 *
 * Every failure carries a `detail` that says what to do. §4.3 makes the close a control rather than a formality, and a
 * control that says "cannot close" without saying why is a control people learn to force.
 */
readonly class CloseCheck
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $passed,
        public bool $blocking,
        public string $detail,
    ) {}

    public static function pass(string $key, string $label, string $detail = ''): self
    {
        return new self($key, $label, true, false, $detail);
    }

    public static function blocks(string $key, string $label, string $detail): self
    {
        return new self($key, $label, false, true, $detail);
    }

    public static function warns(string $key, string $label, string $detail): self
    {
        return new self($key, $label, false, false, $detail);
    }

    public function stopsTheClose(): bool
    {
        return $this->blocking && ! $this->passed;
    }
}
