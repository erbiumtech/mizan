<?php

namespace App\Support\Contracts;

use App\Modules\Core\Models\User;

/**
 * A record that belongs to one person, who may therefore see and act on it.
 *
 * `CommentPolicy` needed to know whether the thing a comment hangs off belongs to the person reading it —
 * so that an employee can see comments on their own payslip without being able to see anyone else's. It
 * answered with `$commentable instanceof Payslip`, which put Core's comment policy in Payroll's debt for a
 * single question. See docs/module-packaging-plan.md §9.
 *
 * The question is now asked of the model, and any commentable model may answer it. That is not just
 * indirection: the self-service visibility only a payslip had is now available to an expense claim, a
 * leave request or an MPR by implementing one method — which is what §9 predicted this row would gain.
 *
 * Deliberately *not* about permissions. A model saying "this is yours" does not grant anything; the policy
 * still requires the relevant permission, and this only widens what "own rows" means.
 */
interface OwnedByUser
{
    /** Is this record the given user's own? */
    public function isOwnedBy(User $user): bool;
}
