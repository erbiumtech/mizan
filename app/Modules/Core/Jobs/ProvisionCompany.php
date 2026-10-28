<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Signup;
use App\Multitenancy\CompanyProvisioner;
use App\Support\Plans;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Throwable;

/**
 * A verified signup becomes a company — docs/saas-launch-plan.md §5.
 *
 * Queued, because provisioning creates a database, migrates two hundred tables
 * and seeds the baseline, which is too long for an Octane worker to hold a
 * request open. `NotTenantAware` explicitly: this job serves the landlord, not a
 * company — there is no company yet — and `RefuseTenantlessTenantAwareJobs`
 * would otherwise refuse it at dispatch, by name.
 *
 * One attempt. The provisioner rolls its own work back on failure, so a retry
 * would be safe in principle; but a failure here is a configuration problem (no
 * tenant connection, a database that already exists) that a second try at the
 * same moment does not fix, and the visitor is better told than kept waiting.
 */
class ProvisionCompany implements NotTenantAware, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $signupId) {}

    public function handle(CompanyProvisioner $provisioner): void
    {
        $signup = Signup::query()->with('user')->find($this->signupId);

        // Gone, or already provisioned by an earlier run: nothing to do, and
        // doing it twice would be a second company for one person.
        if ($signup === null || $signup->company_id !== null || $signup->user === null) {
            return;
        }

        $company = $provisioner->provision(
            name: $signup->company_name,
            creator: $signup->user,
            type: Company::TYPE_BUSINESS,
            profile: $signup->profile,
            // The trial plan: what the intersection in §3 is taken against. The
            // provisioner stores the plan and knows nothing about trials.
            plan: Plans::trialPlan(),
        );

        $company->forceFill([
            'trial_ends_at' => now()->addDays(Plans::trialDays()),
        ])->save();

        $signup->forceFill(['company_id' => $company->getKey()])->save();
    }

    public function failed(?Throwable $exception): void
    {
        Signup::query()->whereKey($this->signupId)->update([
            'failed_at' => now(),
            'failure' => $exception?->getMessage(),
        ]);
    }
}
