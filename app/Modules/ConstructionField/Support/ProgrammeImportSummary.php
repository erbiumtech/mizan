<?php

namespace App\Modules\ConstructionField\Support;

/**
 * What an imported programme did — `docs/construction-management-plan.md` §13.
 *
 * **The warnings are the part that matters.** A programme file is somebody else's document produced by somebody else's
 * tool, and the failure mode of an importer is not throwing: it is quietly importing four hundred activities out of five
 * hundred and reporting success. So this counts every row it *skipped* and says why, and the screen prints those lines
 * rather than a tick.
 *
 * `baselineWritten` is here for the same reason. §13's whole argument for two pairs of dates is that a monthly
 * re-programme must not retire the entitlement it was caused by — so an update import leaves the baseline alone, and the
 * summary says out loud which of the two things just happened.
 */
final class ProgrammeImportSummary
{
    /** @param array<int, string> $warnings */
    public function __construct(
        public readonly string $source,
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $links = 0,
        public readonly bool $baselineWritten = false,
        public readonly array $warnings = [],
    ) {}

    public function total(): int
    {
        return $this->created + $this->updated;
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * One sentence for a notification, and it always says whether the baseline moved.
     *
     * A summary that read "500 activities imported" and left that unsaid would be the silence §13 is written against:
     * the accepted programme is what a claim is measured against, and overwriting it is the most consequential thing an
     * import can do.
     */
    public function describe(): string
    {
        $parts = ["{$this->created} added", "{$this->updated} updated", "{$this->links} links"];

        $parts[] = $this->baselineWritten
            ? 'the baseline was set from this file'
            : 'the baseline was left alone';

        if ($this->hasWarnings()) {
            $parts[] = count($this->warnings).' row(s) skipped';
        }

        return ucfirst(implode(', ', $parts)).'.';
    }
}
