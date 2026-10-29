<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\User;
use App\Support\Contracts\CategorisedNotification;

/**
 * Which kinds of email a person wants — docs/saas-launch-plan.md §14.7.
 *
 * Stored on `users.preferences`, like the language, so it follows somebody across
 * every company they belong to. The stored value is the list of categories switched
 * *off*: a person who has never visited the page has an empty list and gets
 * everything, and a category added later is on by default rather than silently off.
 *
 * **Some categories cannot be switched off.** An invitation, a verification link or
 * a notice that access has been suspended is not a preference — it is how somebody
 * keeps or regains their account, and a person who silenced it would have no way
 * back. Those are in {@see ALWAYS} and the page does not offer them.
 *
 * Email only. The bell in the panel keeps showing everything, because an unread
 * count costs nothing and the reason people silence email is volume in an inbox.
 */
class NotificationPreferences
{
    /** Every category a notification may declare, and what the page calls it. */
    public const CATEGORIES = [
        'payroll' => 'Payslips and payroll',
        'leave' => 'Leave and attendance',
        'approvals' => 'Approvals and requests waiting on you',
        'documents' => 'Documents, letters and expiry warnings',
        'billing' => 'Invoices, quotations and customers',
        'reports' => 'Scheduled reports',
        'monitoring' => 'Monitoring, devices and site alerts',
        'company' => 'Company announcements',
        'account' => 'Account, access and billing for this service',
    ];

    /** @var array<int, string> categories a person may not silence */
    public const ALWAYS = ['account'];

    /** The categories the preferences page offers, in the order it shows them. */
    public static function choosable(): array
    {
        return array_diff_key(self::CATEGORIES, array_flip(self::ALWAYS));
    }

    public static function wants(?User $user, string $category): bool
    {
        if (in_array($category, self::ALWAYS, true) || ! $user instanceof User) {
            return true;
        }

        return ! in_array($category, self::silenced($user), true);
    }

    /** @return array<int, string> */
    public static function silenced(?User $user): array
    {
        return array_values(array_filter(
            (array) (($user?->preferences['notifications_off'] ?? [])),
            'is_string',
        ));
    }

    /** Whether this person has asked not to be emailed this notification. */
    public static function silences(?User $user, object $notification): bool
    {
        return $notification instanceof CategorisedNotification
            && ! self::wants($user, $notification->notificationCategory());
    }

    /** @param  array<int, string>  $offCategories */
    public static function set(User $user, array $offCategories): void
    {
        $off = array_values(array_intersect(array_map('strval', $offCategories), array_keys(self::choosable())));

        // forceFill: `preferences` is guarded, and update() would drop it silently.
        $user->forceFill(['preferences' => array_merge((array) $user->preferences, ['notifications_off' => $off])])->save();
    }
}
