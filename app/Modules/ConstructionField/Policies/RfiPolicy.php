<?php

namespace App\Modules\ConstructionField\Policies;

use App\Modules\ConstructionField\Models\Rfi;
use App\Modules\Core\Models\User;

/**
 * Who raises an RFI, and who answers one — §16.2.
 *
 * **Raising is site's, and needs no argument beyond the obvious**: the person who cannot build without an answer is the
 * person standing in front of the problem. An RFI they cannot raise is a question asked by telephone, answered by
 * telephone, and unprovable.
 *
 * **Recording the answer is the same grant, deliberately.** The answer arrives by email from the Architect and somebody
 * transcribes it; that is clerical work, not an approval, and putting it behind a second permission would leave answers
 * sitting in an inbox while the register says the question is still open — which is worse than the risk it guards.
 *
 * **There is no delete.** §16.2 numbers the register without gaps, and a gap in a register quoted by number is
 * indistinguishable from a removal somebody wanted. Cancellation with a reason is the only way out, and the model
 * refuses deletion outright rather than relying on this policy.
 */
class RfiPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionRfiView');
    }

    public function view(User $user, Rfi $rfi): bool
    {
        return $user->can('ConstructionRfiView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionRfiUpdate');
    }

    /** Editable while it is live: once closed or cancelled the row is the record of what was asked and answered. */
    public function update(User $user, Rfi $rfi): bool
    {
        return $user->can('ConstructionRfiUpdate') && ! $rfi->isClosed();
    }

    public function answer(User $user, Rfi $rfi): bool
    {
        return $user->can('ConstructionRfiUpdate') && ! $rfi->isClosed();
    }

    public function close(User $user, Rfi $rfi): bool
    {
        return $user->can('ConstructionRfiUpdate') && ! $rfi->isClosed();
    }

    /**
     * Raising the delay event this RFI's time impact needs is **the delay register's grant, not this one**.
     *
     * The act creates a contractual notice with a clock on it, which is §13's business — and whoever may ask a question
     * is not necessarily whoever may serve notice on the employer. The action is absent rather than refused for
     * somebody without it, and the register still shows them that the exposure exists.
     */
    public function raiseDelay(User $user, Rfi $rfi): bool
    {
        return $user->can('ConstructionDelayUpdate') && $rfi->timeImpactUnnotified();
    }

    public function delete(User $user, Rfi $rfi): bool
    {
        return false;
    }
}
