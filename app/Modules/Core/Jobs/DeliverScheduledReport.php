<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Models\ReportDelivery;
use App\Modules\Core\Models\ReportSchedule;
use App\Support\Reporting\ReportDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One schedule, rendered and sent — `docs/reports-expansion-plan.md` Phase 8, items 6 and 8.
 *
 * > **Rendering is Phase 4's export in a job**, with the PDF engine's existing per-engine template overrides.
 * > The queue timeouts are already ordered correctly in `config/queue.php`; a report large enough to exceed
 * > them is a report to cap, not a timeout to raise.
 *
 * **The id rather than the model**, because a job's payload outlives the row it was created from: a schedule
 * edited or deleted between dispatch and run should be read as it is now, and `SerializesModels` would either
 * fail loudly on a deleted row or resurrect a stale one. The tenant travels with the job — spatie's
 * `queues_are_tenant_aware_by_default` is on — so the company is already current when `handle()` runs, which
 * is what makes reading the row by id correct in the first place.
 *
 * **`$tries` is `ReportDelivery::MAX_ATTEMPTS`, and the two must agree.** The delivery row counts attempts and
 * `failed()` is where the owner is told; a queue configured to retry more would report a give-up that had not
 * happened, and one configured to retry less would never reach it.
 */
class DeliverScheduledReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = ReportDelivery::MAX_ATTEMPTS;

    public function __construct(public int $scheduleId) {}

    public function handle(ReportDeliveryService $reports): void
    {
        $schedule = ReportSchedule::query()->find($this->scheduleId);

        // Deleted, or switched off, between dispatch and here. Not an error: the schedule is the authority on
        // whether it should still run, and it is read now rather than when the job was queued.
        if ($schedule === null || ! $schedule->is_active || $schedule->isSuspended()) {
            return;
        }

        $reports->deliver($schedule);
    }

    /**
     * The attempts are spent — tell the owner.
     *
     * Here rather than inside `deliver()` because this is the only place that knows the difference between "a
     * render failed" and "this report has stopped arriving", and only the second is worth an email.
     */
    public function failed(Throwable $exception): void
    {
        $schedule = ReportSchedule::query()->find($this->scheduleId);

        if ($schedule !== null) {
            app(ReportDeliveryService::class)->giveUp($schedule, $exception->getMessage());
        }
    }
}
