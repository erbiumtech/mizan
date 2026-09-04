<?php

namespace App\Modules\Core\Listeners;

use App\Support\Broadcasting;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * No socket, no broadcast job.
 *
 * Every notification here lists `broadcast` among its channels, and with no broadcaster configured each one
 * became a `BroadcastNotificationCreated` job that failed on connect — six in `failed_jobs` after one
 * afternoon in development, one per notification forever in a production that runs without Reverb. The
 * bell entry itself had already landed; the failure was noise about a push that could never happen.
 *
 * **The backstop, not the first line.** Every notification's `via()` now runs its channels through
 * `Broadcasting::channels()`, which is where Laravel decides what to queue — so on an installation without a
 * socket no broadcast job exists at all. This listener fires later, inside a job, at send time: it catches
 * a notification that forgot to call the helper, or one from a package whose `via()` is not ours. Withdrawing
 * the channel here still avoids the failed job; it just cannot avoid the empty one.
 *
 * `NotificationSending` is dispatched per channel and `until()`-ed, so returning false withdraws exactly
 * that channel and nothing else.
 */
class SkipBroadcastWithoutBroadcaster
{
    public function handle(NotificationSending $event): ?bool
    {
        if ($event->channel === 'broadcast' && ! Broadcasting::isConfigured()) {
            return false;
        }

        return null;
    }
}
