<?php

namespace App\Support;

use Closure;

/**
 * What an outgoing webhook can be subscribed to — docs/saas-launch-plan.md §14.9.
 *
 * **The event types come from the domain events this application already fires**,
 * registered by the module that fires them. Inventing a second vocabulary of
 * "webhook events" beside them would mean two lists to keep in step, and the one
 * that rotted would be the one nobody ran.
 *
 * Each registration says how to turn its event into a payload. That is the module's
 * job and nobody else's: the payload is a public contract with somebody's
 * integration, and it must not be whatever `toArray()` happens to return the day a
 * column is added. A closure that names its fields is a payload that stays the same
 * until somebody decides otherwise.
 */
final class Webhooks
{
    /** @var array<string, array{label: string, event: class-string, payload: Closure, sort: int}> */
    private static array $types = [];

    /**
     * @param  string  $key  what an endpoint subscribes to, e.g. `employee.joined`
     * @param  class-string  $event  the domain event that fires it
     * @param  Closure(object): array<string, mixed>  $payload
     */
    public static function register(string $key, string $label, string $event, Closure $payload, int $sort = 100): void
    {
        self::$types[$key] = compact('label', 'event', 'payload', 'sort');
    }

    /** @return array<string, array{label: string, event: class-string, payload: Closure, sort: int}> */
    public static function all(): array
    {
        $types = self::$types;
        uasort($types, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return $types;
    }

    /** @return array<string, string> key => label, for the endpoint form */
    public static function options(): array
    {
        return collect(self::all())->map(fn (array $type): string => $type['label'])->all();
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** The domain event classes to listen on, each with the keys it feeds. @return array<class-string, array<int, string>> */
    public static function byEvent(): array
    {
        $byEvent = [];

        foreach (self::all() as $key => $type) {
            $byEvent[$type['event']][] = $key;
        }

        return $byEvent;
    }

    /**
     * The body for one event, or null where this event does not describe itself.
     *
     * Null rather than an exception: a payload builder that throws on one odd record
     * must not take down the thing that fired the event, which is a hire or a payslip.
     *
     * @return array<string, mixed>|null
     */
    public static function payloadFor(string $key, object $event): ?array
    {
        if (! isset(self::$types[$key])) {
            return null;
        }

        try {
            return (self::$types[$key]['payload'])($event);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function flush(): void
    {
        self::$types = [];
    }
}
