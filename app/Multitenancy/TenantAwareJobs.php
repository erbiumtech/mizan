<?php

namespace App\Multitenancy;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\CallQueuedClosure;
use ReflectionClass;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Spatie\Multitenancy\Jobs\TenantAware;

/**
 * Whether a job needs a tenant — the package's rule, answered *before* the job is queued.
 *
 * spatie/laravel-multitenancy answers this question inside the worker (`MakeQueueTenantAwareAction`),
 * where the answer to "yes, and there is no tenant" is to delete the job. The method that decides is
 * protected there, so the rule is restated here rather than reached for by reflection, reading the same
 * config keys in the same order — and `TenantAwareQueueTest` pins the two to the same answers for every
 * queueable shape this application uses. If the package changes the rule, that test is what says so.
 *
 * A queueable wrapper is unwrapped first, exactly as the package does: a `SendQueuedNotifications` job is
 * judged by its notification, a `CallQueuedListener` by its listener class, and so on.
 */
final class TenantAwareJobs
{
    public static function requiresTenant(object|string|null $job): bool
    {
        $subject = self::unwrap($job);

        if ($subject === null) {
            return (bool) config('multitenancy.queues_are_tenant_aware_by_default');
        }

        $reflection = new ReflectionClass($subject);

        if ($reflection->implementsInterface(config('multitenancy.tenant_aware_interface', TenantAware::class))) {
            return true;
        }

        if ($reflection->implementsInterface(config('multitenancy.not_tenant_aware_interface', NotTenantAware::class))) {
            return false;
        }

        if (in_array($reflection->getName(), (array) config('multitenancy.tenant_aware_jobs', []), true)) {
            return true;
        }

        if (in_array($reflection->getName(), (array) config('multitenancy.not_tenant_aware_jobs', []), true)) {
            return false;
        }

        return (bool) config('multitenancy.queues_are_tenant_aware_by_default');
    }

    /**
     * The job as a developer would name it: the notification, listener or mailable inside Laravel's
     * wrapper, with the wrapper in brackets — `App\Notifications\PayslipIssued (via
     * SendQueuedNotifications)`. A refusal that named only `SendQueuedNotifications` would send them
     * grepping for the wrong class.
     */
    public static function describe(object|string|null $job): string
    {
        $subject = self::unwrap($job);
        $subjectName = is_object($subject) ? $subject::class : (string) ($subject ?? 'unknown job');
        $jobName = is_object($job) ? $job::class : (string) ($job ?? 'unknown job');

        return $subjectName === $jobName ? $jobName : "{$subjectName} (via ".class_basename($jobName).')';
    }

    /** The thing the job is *about*, when the job is one of Laravel's queueable wrappers. */
    private static function unwrap(object|string|null $job): object|string|null
    {
        if (! is_object($job)) {
            return $job;
        }

        $property = (array) config('multitenancy.queueable_to_job', [
            SendQueuedMailable::class => 'mailable',
            SendQueuedNotifications::class => 'notification',
            CallQueuedClosure::class => 'closure',
            CallQueuedListener::class => 'class',
            BroadcastEvent::class => 'event',
        ]);

        foreach ($property as $wrapper => $name) {
            if ($job instanceof $wrapper) {
                // Public on every wrapper Laravel ships; read through a bound closure so a protected one
                // on a wrapper added later does not turn this into a fatal.
                $inner = (fn () => $this->{$name} ?? null)->call($job);

                return is_object($inner) || is_string($inner) ? $inner : null;
            }
        }

        return $job;
    }
}
