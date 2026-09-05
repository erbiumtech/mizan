<?php

namespace App\Multitenancy;

use RuntimeException;

/**
 * Thrown at dispatch when a tenant-aware job is queued with no tenant current.
 *
 * The alternative is what the package does on its own: the worker finds no tenant id in the payload,
 * **deletes the job** and throws — no `failed_jobs` row, no output, nothing in the panel. A payslip
 * notification queued that way simply never arrives, and the first anybody hears of it is the employee
 * asking. Failing here, in the request or command that made the mistake, names the job and the fix.
 */
class TenantRequiredToQueue extends RuntimeException
{
    public static function for(string $job): self
    {
        return new self(
            "{$job} is tenant-aware but was queued with no tenant current. The worker would delete it "
            .'without a trace. Make the company current before dispatching (`$company->makeCurrent()`), or '
            .'mark the job `NotTenantAware` if it genuinely serves no company.'
        );
    }
}
