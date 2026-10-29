<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Models\MessageLog;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Str;

/**
 * Every mail in the log — docs/saas-launch-plan.md §14.7.
 *
 * The envelope, never the body. "Did the payslip actually go, and to which
 * address" is the question this answers; keeping the message itself would make the
 * log a second copy of every payslip figure and every personal letter, in a table
 * that is read by anybody who can see the log.
 */
class LogSentMail
{
    public function handle(MessageSent $event): void
    {
        $recipients = collect($event->message->getTo())
            ->map(fn ($address): string => $address->getAddress())
            ->implode(', ');

        MessageLog::record([
            'channel' => MessageLog::CHANNEL_MAIL,
            'recipient' => Str::limit($recipients ?: 'unknown', 240, ''),
            'subject' => Str::limit((string) $event->message->getSubject(), 240, ''),
            'purpose' => $this->purpose($event),
            // On the SentMessage rather than the Email: the id is assigned by the
            // transport as it goes, so the message itself does not have one yet.
            'reference' => $event->sent->getMessageId(),
        ]);
    }

    /**
     * What the mail was, in words.
     *
     * Laravel puts the notification's class name in the view data, which is the only
     * handle on "what kind of mail was this" that works without every notification
     * having to announce itself. A plain Mailable has none, and shows as its subject
     * alone.
     */
    private function purpose(MessageSent $event): ?string
    {
        $class = $event->data['__laravel_notification'] ?? null;

        if (! is_string($class) || $class === '') {
            return null;
        }

        return Str::of(class_basename($class))->kebab()->replace('-', ' ')->ucfirst()->toString();
    }
}
