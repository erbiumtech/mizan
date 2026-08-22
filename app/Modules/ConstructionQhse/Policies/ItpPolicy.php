<?php

namespace App\Modules\ConstructionQhse\Policies;

use App\Modules\ConstructionQhse\Models\Itp;
use App\Modules\Core\Models\User;

/**
 * Who writes a quality plan, and who approves it — §17.1.
 *
 * **Approving is its own permission**, and unlike most of this suite the argument is external rather than internal: an
 * ITP is the document a certification body audits against, and the signature on it is a statement to a third party about
 * how the work will be controlled. Whoever drafts the plan is rarely whoever is answerable for that.
 *
 * Reading it is site's — the point of an ITP is that the people doing the work know what will be inspected and when.
 */
class ItpPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionItpView');
    }

    public function view(User $user, Itp $itp): bool
    {
        return $user->can('ConstructionItpView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionItpUpdate');
    }

    /** Only a draft is editable: an issued plan is a document people are working to. */
    public function update(User $user, Itp $itp): bool
    {
        return $user->can('ConstructionItpUpdate') && $itp->isEditable();
    }

    public function issue(User $user, Itp $itp): bool
    {
        return $user->can('ConstructionItpUpdate') && $itp->isEditable();
    }

    public function approve(User $user, Itp $itp): bool
    {
        return $user->can('ConstructionItpApprove') && $itp->status === Itp::STATUS_ISSUED;
    }

    /** Revising is drafting a successor, so it sits with whoever may draft. */
    public function revise(User $user, Itp $itp): bool
    {
        return $user->can('ConstructionItpUpdate') && ! $itp->isSuperseded();
    }

    /**
     * Deletable only while it is an unissued draft.
     *
     * An issued plan is a controlled document, and a superseded one is what past inspections point at.
     */
    public function delete(User $user, Itp $itp): bool
    {
        return $user->can('ConstructionItpUpdate') && $itp->isEditable();
    }
}
