<?php

namespace App\Modules\ConstructionQhse\Support;

/**
 * One safety rate, with its base — or a refusal to print one — `docs/construction-management-plan.md` §17.6.
 *
 * Two rules of §17.6 are enforced by this class being the only thing `SafetyIndicators` returns.
 *
 * **The base travels with the figure.** §17.6: "a frequency rate without its base is a number that gets compared
 * against a competitor's figure computed on a different one, and 1,000,000 against 200,000 is a factor of five with
 * both called *the standard*." So `value` and `base` are never separable, `describe()` always prints both, and there is
 * no way to obtain the figure from this object without the base being right there in the same expression.
 *
 * **A rate that cannot be computed is not a zero.** `value` is null in that case, `reason` says why, and `describe()`
 * returns §17.6's exact words — "insufficient exposure data" — followed by the sentence somebody can act on. The base
 * is still carried, because "we could not compute this per million hours" is a more useful refusal than "we could not
 * compute this".
 *
 * The numerator is kept too: a rate of 5.0 per million on two incidents in 400,000 hours is a fact about a small
 * sample, and printing the rate without the count invites somebody to read it as a trend.
 */
readonly class SafetyRate
{
    private function __construct(
        public string $label,
        public ?float $value,
        public int $base,
        public ?ExposureHours $exposure,
        public ?int $numerator,
        public ?string $numeratorLabel,
        public ?string $reason,
    ) {}

    public static function of(
        string $label,
        float $value,
        int $base,
        ExposureHours $exposure,
        int $numerator,
        string $numeratorLabel,
    ): self {
        return new self($label, $value, $base, $exposure, $numerator, $numeratorLabel, null);
    }

    public static function unavailable(string $label, string $reason, int $base): self
    {
        return new self($label, null, $base, null, null, null, $reason);
    }

    public function isAvailable(): bool
    {
        return $this->value !== null;
    }

    /** The base as a phrase, because "per 1,000,000 hours worked" is what makes the figure comparable. */
    public function baseLabel(): string
    {
        return 'per '.number_format($this->base).' hours worked';
    }

    /** The whole rate as one printable sentence — figure, base, and what it was computed from. */
    public function describe(): string
    {
        if (! $this->isAvailable()) {
            return 'Insufficient exposure data. '.$this->reason;
        }

        return number_format($this->value, 2).' '.$this->baseLabel()
            .' ('.$this->numerator.' '.$this->numeratorLabel.' over '.number_format($this->exposure->hours, 0)
            .' hours from '.$this->exposure->source.')';
    }

    /**
     * What a table cell shows.
     *
     * Deliberately §17.6's phrase and never a dash or a zero: a dash in a numeric column is read as nothing happened,
     * and this means nobody knows.
     */
    public function display(): string
    {
        return $this->isAvailable()
            ? number_format($this->value, 2)
            : 'Insufficient exposure data';
    }
}
