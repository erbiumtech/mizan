<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;

/**
 * What leaves this application when an error is reported to Sentry.
 *
 * A payroll system's exception messages carry PII in the places nobody plans for:
 * a unique-constraint violation quotes the duplicate email, a failed notification
 * names its recipient, a validation log line holds the CNIC somebody mistyped. So
 * everything textual is scrubbed on the way out — exception values, the log
 * message, request data, extra context, and each breadcrumb as it is recorded.
 *
 * Wired in config/sentry.php as STATIC CALLABLES, never closures: deploy.sh runs
 * `config:cache`, and a closure in any config file makes every artisan command
 * fail with "unserializable". The same file turns request bodies off entirely
 * (`max_request_body_size: none`) — the cheapest scrub is the field never sent.
 *
 * ponytail: three regexes, not a taxonomy — emails, CNICs, and any 8+ digit run
 * (phones, bank accounts, the digits of an IBAN). Over-matching a timestamp is
 * fine; under-matching a salary reference is not. Add a pattern when a real event
 * shows a real leak.
 */
class SentryScrubber
{
    /** Order matters: an email is matched whole before its digits can become a [number]. */
    private const PATTERNS = [
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u' => '[email]',
        '/\b\d{5}-\d{7}-\d\b/' => '[cnic]',
        '/\+?\d(?:[\s().-]?\d){7,}/' => '[number]',
    ];

    public static function scrub(Event $event): Event
    {
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::text($exception->getValue()));
        }

        if ($event->getMessage() !== null) {
            $event->setMessage(
                self::text($event->getMessage()),
                array_map(self::value(...), $event->getMessageParams()),
                $event->getMessageFormatted() === null ? null : self::text($event->getMessageFormatted()),
            );
        }

        $event->setRequest(self::values($event->getRequest()));
        $event->setExtra(self::values($event->getExtra()));

        return $event;
    }

    public static function scrubBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        if ($breadcrumb->getMessage() !== null) {
            $breadcrumb = $breadcrumb->withMessage(self::text($breadcrumb->getMessage()));
        }

        foreach ($breadcrumb->getMetadata() as $key => $metadata) {
            $breadcrumb = $breadcrumb->withMetadata($key, self::value($metadata));
        }

        return $breadcrumb;
    }

    private static function text(string $value): string
    {
        return preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value);
    }

    private static function value(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::text($value);
        }

        if (is_array($value)) {
            return self::values($value);
        }

        return $value;
    }

    /** @param  array<array-key, mixed>  $values */
    private static function values(array $values): array
    {
        return array_map(self::value(...), $values);
    }
}
