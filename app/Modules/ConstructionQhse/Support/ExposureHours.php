<?php

namespace App\Modules\ConstructionQhse\Support;

/**
 * The denominator of every safety rate, or the reason there isn't one — `docs/construction-management-plan.md` §17.6.
 *
 * **This class exists so that "no exposure hours" cannot be represented as `0.0`.** §17.6: "with no diary the
 * denominator is zero and the frequency rate renders as `0.00`, which reads as a perfect safety record and actually
 * means nobody filled anything in." A float has no way to carry that distinction; this does, and there is no accessor
 * that hands out a number without the caller having passed `isAvailable()` first.
 *
 * **It also carries the source**, because §17.6 requires the report to print "which one it used" — the site diary or
 * Timesheets, never both, since counting both halves every rate.
 */
readonly class ExposureHours
{
    private function __construct(
        public ?float $hours,
        public ?string $source,
        public ?string $reason,
    ) {}

    public static function of(float $hours, string $source): self
    {
        return new self($hours, $source, null);
    }

    /**
     * No denominator, and a sentence saying why.
     *
     * The reason is required rather than optional: "insufficient exposure data" on its own tells somebody a rate is
     * missing and not what to do about it, and §17.6's whole point is that this state has to be actionable.
     */
    public static function unavailable(string $reason): self
    {
        return new self(null, null, $reason);
    }

    public function isAvailable(): bool
    {
        return $this->hours !== null && $this->hours > 0.0;
    }

    /** For the face of the report: the figure and where it came from, in one phrase. */
    public function describe(): string
    {
        return $this->isAvailable()
            ? number_format($this->hours, 0).' exposure hours, from '.$this->source
            : 'Insufficient exposure data. '.$this->reason;
    }
}
