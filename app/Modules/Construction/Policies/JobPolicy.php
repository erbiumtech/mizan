<?php

namespace App\Modules\Construction\Policies;

use App\Modules\Construction\Models\Job;
use App\Modules\Core\Models\User;

/**
 * Who may see and change a job.
 *
 * Registered explicitly in `ConstructionServiceProvider` — Laravel's `App\Models\X -> App\Policies\XPolicy`
 * guess cannot resolve a model in a module directory, and Filament treats a model with no policy as
 * **allowed**, so a missing registration is an open resource rather than a closed one.
 * `ModuleCoverageTest` fails the build for one.
 *
 * Row scoping is deliberately not here. §1 puts it in `JobAccess` and a `getEloquentQuery()` filter, the same
 * split `App\Support\EmployeeAccess` already uses: the policy answers "may this role do this at all", the
 * query answers "to which rows". Mixing them means a permission change silently widens visibility.
 */
class JobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionJobView');
    }

    public function view(User $user, Job $job): bool
    {
        return $user->can('ConstructionJobView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionJobCreate');
    }

    public function update(User $user, Job $job): bool
    {
        return $user->can('ConstructionJobUpdate');
    }

    /**
     * A closed job is not deletable by anybody.
     *
     * Its certificates, retention ledger and final account are the contractual record of a completed
     * contract, and the permission exists for a job raised in error rather than for one that ran.
     */
    public function delete(User $user, Job $job): bool
    {
        return $user->can('ConstructionJobDelete') && $job->closed_at === null;
    }
}
