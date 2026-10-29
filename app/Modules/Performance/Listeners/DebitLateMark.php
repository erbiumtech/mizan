<?php

namespace App\Modules\Performance\Listeners;

use App\Modules\Employees\Events\LateMarkRecorded;
use App\Modules\Performance\Models\MeritEntry;
use App\Modules\Performance\Services\MeritLedger;

/**
 * A late mark beyond grace as a merit debit — docs/saas-launch-plan.md §8.1.
 * Behind `performance.merit.late_mark_points`, zero as shipped; one debit per
 * attendance day however many times the day is re-recorded.
 */
class DebitLateMark
{
    public function __construct(private readonly MeritLedger $ledger) {}

    public function handle(LateMarkRecorded $event): void
    {
        if (! modules()->enabled('performance')) {
            return;
        }

        $points = (int) setting('performance.merit.late_mark_points', config('performance.merit.late_mark_points', 0));

        if ($points <= 0 || $this->ledger->hasEntryFor(MeritEntry::SOURCE_LATE_MARK, $event->attendanceDayId)) {
            return;
        }

        $this->ledger->record(
            $event->employeeId,
            -$points,
            "Late by {$event->lateMinutes} minutes on {$event->date}",
            MeritEntry::SOURCE_LATE_MARK,
            $event->attendanceDayId,
            occurredOn: $event->date,
        );
    }
}
