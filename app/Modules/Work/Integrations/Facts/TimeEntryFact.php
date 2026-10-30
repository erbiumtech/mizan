<?php

namespace App\Modules\Work\Integrations\Facts;

use Illuminate\Support\Carbon;

/**
 * One time entry as a tracker states it — docs/project-management-plan.md §8.8.
 *
 * `end` is null while the timer runs, and a running entry is written nowhere: the ledger holds
 * hours, not intentions. `deleted` carries the tracker's own delete so the row here goes with it.
 */
final class TimeEntryFact
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $externalUserId,
        public readonly ?string $externalProjectId,
        public readonly ?string $externalTaskId,
        public readonly ?string $description,
        public readonly Carbon $start,
        public readonly ?Carbon $end,
        public readonly bool $billable,
        public readonly bool $deleted = false,
        public readonly ?string $userEmail = null,
        public readonly ?string $userName = null,
    ) {}

    public function isRunning(): bool
    {
        return $this->end === null && ! $this->deleted;
    }

    public function minutes(): int
    {
        return $this->end === null ? 0 : max(0, (int) round($this->start->diffInSeconds($this->end) / 60));
    }
}
