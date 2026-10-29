<?php

namespace App\Support\Contracts;

/**
 * A notification that says which kind of mail it is — docs/saas-launch-plan.md §14.7.
 *
 * Preferences are per category rather than per notification because nobody wants a
 * settings page with thirty switches on it, and because a new notification should
 * arrive already covered by the choice somebody made a year ago: "I do not want
 * monitoring email" is a durable statement, "I do not want EnvironmentRecovered" is
 * a list that goes stale.
 *
 * The category is declared here, by the notification, rather than held in a list
 * somewhere central. A central list would have to name every module's notification
 * classes from one place, which is exactly the coupling the module graph exists to
 * prevent — and it would rot the first time somebody added a notification and forgot
 * to register it. `NotificationCoverageTest` makes the forgetting fail instead.
 */
interface CategorisedNotification
{
    /** One of the keys in {@see \App\Modules\Core\Support\NotificationPreferences::CATEGORIES}. */
    public function notificationCategory(): string;
}
