<?php

namespace App\Multitenancy;

use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\Context;

/**
 * Refuses, at dispatch, a tenant-aware job that carries no tenant.
 *
 * `JobQueueing` fires after the payload is built and before it is pushed — the last moment the mistake can
 * be reported to whoever made it. The tenant travels in Laravel's Context (the package puts it there when a
 * company is made current, and Context is serialised into every payload), so "is there a tenant" is
 * exactly the question the worker will ask later, asked now.
 *
 * Not registered for the `sync` driver's sake — `SyncQueue` never raises this event — which is why the
 * test for it runs on the `database` connection.
 */
class RefuseTenantlessTenantAwareJobs
{
    public function handle(JobQueueing $event): void
    {
        if (! TenantAwareJobs::requiresTenant($event->job)) {
            return;
        }

        if (Context::has((string) config('multitenancy.current_tenant_context_key', 'tenantId'))) {
            return;
        }

        throw TenantRequiredToQueue::for(TenantAwareJobs::describe($event->job));
    }
}
