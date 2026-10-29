<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\NotificationPreferences;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * Drops the mail a person has asked not to receive — docs/saas-launch-plan.md §14.7.
 *
 * Returning false from this event cancels that one channel, which is why the test is
 * on `$event->channel` rather than on the notification: the bell and the broadcast go
 * either way, and only the email stops.
 */
class RespectNotificationPreferences
{
    public function handle(NotificationSending $event): bool
    {
        if ($event->channel !== 'mail' || ! $event->notifiable instanceof User) {
            return true;
        }

        return ! NotificationPreferences::silences($event->notifiable, $event->notification);
    }
}
