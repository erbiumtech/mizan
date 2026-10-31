<?php

namespace App\Modules\Work\Support;

use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Services\Timers;
use RuntimeException;

/**
 * The tracker's task, made when the item starts rather than on the first timer — §8.8 and §16.9.
 *
 * Off by default, because a task created for every item that was ever started fills a Clockify
 * workspace with tasks nobody timed. A project that wants it says so. A failure to create the task
 * is reported, never allowed to stop the transition: the status engine is not the tracker's.
 */
class TimeTaskOnProgress
{
    public static function register(): void
    {
        WorkItem::saved(function (WorkItem $item): void {
            if (! $item->wasChanged('work_status_id')) {
                return;
            }

            $status = WorkStatus::query()->find($item->work_status_id);

            if ($status === null || $status->category !== WorkStatus::IN_PROGRESS) {
                return;
            }

            $timers = app(Timers::class);

            if (! $timers->tasksOnProgress($item->project) || $timers->timeProjectFor($item) === null) {
                return;
            }

            try {
                $timers->ensureTask($item);
            } catch (RuntimeException $e) {
                report($e);
            }
        });
    }
}
