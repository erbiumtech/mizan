<?php

namespace App\Modules\ConstructionCosting\Support;

/**
 * Whether a month can be closed, and what somebody should know either way — `docs/construction-management-plan.md` §4.3.
 *
 * §4.3's second mechanism is "the period cannot be closed while unbalanced", and its third is that somebody holding
 * `ConstructionPeriodForceClose` may close it anyway **with a stated reason**. This class is what stands between them:
 * the list of gates, which of them are shut, and — for a forced close — what was overridden, so the reason recorded
 * against the month says what it was a reason *for*.
 */
readonly class CloseChecklist
{
    /** @param array<int, CloseCheck> $checks */
    public function __construct(
        public string $periodStart,
        public array $checks,
    ) {}

    public function canClose(): bool
    {
        return $this->blockers() === [];
    }

    /** @return array<int, CloseCheck> */
    public function blockers(): array
    {
        return array_values(array_filter($this->checks, fn (CloseCheck $c): bool => $c->stopsTheClose()));
    }

    /** @return array<int, CloseCheck> */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->checks,
            fn (CloseCheck $c): bool => ! $c->passed && ! $c->blocking,
        ));
    }

    /**
     * What a forced close would override, as sentences — recorded on the period alongside the reason.
     *
     * Without this the note says "closing anyway, the client needs the report" and nothing says what was true when it
     * was written. §4.3's whole point about a forced close is that the difference "stays visible in every later period",
     * and a reason with no facts beside it is not visible in any useful sense.
     *
     * @return array<int, string>
     */
    public function overriddenSentences(): array
    {
        return array_map(
            fn (CloseCheck $c): string => $c->label.': '.$c->detail,
            [...$this->blockers(), ...$this->warnings()],
        );
    }

    public function describe(): string
    {
        if ($this->canClose() && $this->warnings() === []) {
            return 'Every check passes.';
        }

        $parts = [];

        foreach ($this->blockers() as $check) {
            $parts[] = 'Blocked — '.$check->label.': '.$check->detail;
        }

        foreach ($this->warnings() as $check) {
            $parts[] = 'Worth knowing — '.$check->label.': '.$check->detail;
        }

        return implode(' ', $parts);
    }
}
