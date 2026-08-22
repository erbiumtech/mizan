<?php

namespace App\Modules\ConstructionContracts\Policies;

use App\Modules\ConstructionContracts\Models\ComplianceDocument;
use App\Modules\Core\Models\User;

/**
 * Who maintains the compliance register, and who may certify around it — `docs/construction-management-plan.md` §12.
 *
 * **Recording and verifying are the same grant**, deliberately: the person who files the certificate is the person who
 * reads it, and splitting them would mean a register full of documents nobody had looked at, which is exactly the state
 * `verified_at` exists to distinguish.
 *
 * **Waiving is the override grant**, not the update one. A waiver says "we accept this subcontractor will never produce
 * this", which is a standing decision about risk rather than filing — §18.2 names `ConstructionComplianceOverride` among
 * the permissions that matter, and this is one of its two uses. The other is certifying past a block.
 *
 * There is no `delete`: a policy that expired is the evidence that it expired, and the register's whole value is that
 * it can be read backwards.
 */
class ComplianceDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionComplianceView');
    }

    public function view(User $user, ComplianceDocument $document): bool
    {
        return $user->can('ConstructionComplianceView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionComplianceUpdate');
    }

    public function update(User $user, ComplianceDocument $document): bool
    {
        return $user->can('ConstructionComplianceUpdate') && ! $document->isWaived();
    }

    /** Reading a document and saying so — the same person who filed it. */
    public function verify(User $user, ComplianceDocument $document): bool
    {
        return $user->can('ConstructionComplianceUpdate') && $document->verified_at === null;
    }

    /** Accepting that it will never arrive, which is a decision about risk. */
    public function waive(User $user, ComplianceDocument $document): bool
    {
        return $user->can('ConstructionComplianceOverride') && ! $document->isWaived();
    }
}
