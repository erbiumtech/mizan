<?php

namespace Tests\Feature;

use App\Modules\Core\Models\EmailTemplate;
use App\Modules\Core\Support\NotificationPreferences;
use App\Support\Contracts\CategorisedNotification;
use Tests\TestCase;

/**
 * "Email templates cover every mail the app sends" — docs/saas-launch-plan.md §14.7.
 *
 * A guard rather than a behaviour test, and the reason is that the thing being
 * protected is a habit. Somebody adding the thirty-fifth notification next year will
 * not read §14.7, and a company that has reworded every email it sends except the
 * newest one has not got what the plan promised. So the build says so.
 */
class NotificationCoverageTest extends TestCase
{
    /**
     * The mails that are deliberately not overridable, and why.
     *
     * Both are sent with no company current — one because access has just been
     * suspended, one because the notification is explicitly `NotTenantAware` — so
     * there is no company database to read a template out of. `SignupVerification`
     * is the same situation from the other end: there is no company yet.
     *
     * @var array<class-string, string>
     */
    private const UNTEMPLATED = [
        \App\Notifications\SubscriptionNotice::class => 'sent from the platform after access is suspended',
        \App\Notifications\TenantExportReady::class => 'NotTenantAware: built with no company current',
        \App\Notifications\SignupVerification::class => 'there is no company yet',
    ];

    public function test_every_mail_the_application_sends_can_be_reworded_by_the_company(): void
    {
        $missing = [];

        foreach ($this->mailNotifications() as $class => $source) {
            if (array_key_exists($class, self::UNTEMPLATED) || str_contains($source, 'TemplatedMail::')) {
                continue;
            }

            $missing[] = $class;
        }

        $this->assertSame([], $missing, implode("\n", [
            'These notifications build a mail without passing it through TemplatedMail, so a company',
            'cannot change a word of what they say. Wrap the finished message:',
            '',
            "    return TemplatedMail::wrap(\$mail, 'your_key', ['employee_name' => ..., 'company' => ...]);",
            '',
            'then declare the key and its placeholders in EmailTemplate::PLACEHOLDERS. If the mail truly',
            'cannot be templated — no company is current when it is sent — add it to UNTEMPLATED above',
            'with the reason.',
        ]));
    }

    public function test_every_key_a_notification_uses_is_declared_with_its_placeholders(): void
    {
        $undeclared = array_values(array_diff($this->keysUsed(), array_keys(EmailTemplate::PLACEHOLDERS)));

        $this->assertSame([], $undeclared,
            'These template keys are used by a notification but not declared in EmailTemplate::PLACEHOLDERS, '
            .'so the form offers no way to write one and the placeholder help is empty.');
    }

    public function test_every_declared_key_belongs_to_a_mail_that_is_still_sent(): void
    {
        // All three overdue reminders are one notification that picks its key from how many
        // times the invoice has already been chased, so none of the three literals appears
        // anywhere to be found.
        $runtime = ['invoice_overdue', 'invoice_overdue_2', 'invoice_overdue_3'];

        $orphans = array_values(array_diff(array_keys(EmailTemplate::PLACEHOLDERS), $this->keysUsed(), $runtime));

        $this->assertSame([], $orphans,
            'These keys are offered in the dropdown but no notification reads them, so a company can write '
            .'a template that will never be used. Delete the key, or wire the notification that should read it.');
    }

    public function test_every_mail_notification_declares_a_category_people_can_silence_it_by(): void
    {
        $problems = [];

        foreach (array_keys($this->mailNotifications()) as $class) {
            $notification = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

            if (! $notification instanceof CategorisedNotification) {
                $problems[$class] = 'declares no category';

                continue;
            }

            $category = $notification->notificationCategory();

            if (! array_key_exists($category, NotificationPreferences::CATEGORIES)) {
                $problems[$class] = "category '{$category}' is not one of ".implode(', ', array_keys(NotificationPreferences::CATEGORIES));
            }
        }

        $this->assertSame([], $problems,
            'Every notification that sends mail must implement CategorisedNotification, so somebody who has '
            .'asked not to be emailed about monitoring is not emailed about the newest monitoring alert.');
    }

    /**
     * Every notification class that builds a mail, with its source.
     *
     * Read off disk rather than from a registry because there is no registry: a
     * notification is a class somebody writes and calls `notify()` with, and the
     * only complete list of them is the files.
     *
     * @return array<class-string, string>
     */
    private function mailNotifications(): array
    {
        $found = [];

        foreach ($this->notificationFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (! str_contains($source, 'function toMail')) {
                continue;
            }

            preg_match('/^namespace (.+);$/m', $source, $namespace);
            $found[$namespace[1].'\\'.basename($path, '.php')] = $source;
        }

        ksort($found);

        return $found;
    }

    /** @return array<int, string> */
    private function keysUsed(): array
    {
        $keys = [];

        foreach ($this->notificationFiles() as $path) {
            preg_match_all(
                "/TemplatedMail::(?:apply|wrap)\(\s*(?:[^,]+,\s*)?'([a-z0-9_]+)'/",
                (string) file_get_contents($path),
                $matches,
            );

            $keys = array_merge($keys, $matches[1]);
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /** @return array<int, string> */
    private function notificationFiles(): array
    {
        $files = glob(app_path('Notifications/*.php')) ?: [];

        foreach ((glob(app_path('Modules/*/Notifications/*.php')) ?: []) as $path) {
            $files[] = $path;
        }

        sort($files);

        return $files;
    }
}
