<?php

namespace App\Modules\ConstructionContracts\Policies;

use App\Modules\ConstructionContracts\Models\ComplianceRequirement;
use App\Modules\Core\Models\User;

/**
 * Who decides what subcontractors must produce — `docs/construction-management-plan.md` §12.
 *
 * **Setting a requirement is not the same as filing a document**, and it is the heavier act: a requirement that blocks
 * certification will stop payments on every subcontract that has one, so it sits with the override grant rather than
 * with whoever maintains the register.
 *
 * That is a deliberate asymmetry. Anybody who files insurance certificates can file them; deciding that public
 * liability blocks payment on every contract in the company is a policy decision.
 */
class ComplianceRequirementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionComplianceView');
    }

    public function view(User $user, ComplianceRequirement $requirement): bool
    {
        return $user->can('ConstructionComplianceView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionComplianceOverride');
    }

    public function update(User $user, ComplianceRequirement $requirement): bool
    {
        return $user->can('ConstructionComplianceOverride');
    }

    public function delete(User $user, ComplianceRequirement $requirement): bool
    {
        return $user->can('ConstructionComplianceOverride');
    }
}
