<?php

namespace App\Modules\ConstructionQhse\Policies;

use App\Modules\ConstructionQhse\Models\Permit;
use App\Modules\Core\Models\User;

/**
 * Who requests a permit and who issues one — §17.5.
 *
 * **`ConstructionPermitIssue` is separate from requesting, and this is the sharpest segregation in the module.** Issuing
 * a permit *authorises high-risk work*: hot work in a finished building, entry into a confined space, a lift over a live
 * road. The person who wants to do the work is the last person who should decide it is safe to, and every permit-to-work
 * regime in the world is built on that separation.
 *
 * Requesting is wide — a supervisor who cannot raise a permit is a supervisor whose gang works without one — and closing
 * out sits with issuing, because the close-out asserts the area was walked and made safe.
 */
class PermitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionPermitView');
    }

    public function view(User $user, Permit $permit): bool
    {
        return $user->can('ConstructionPermitView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionPermitRequest');
    }

    /** A draft is the requester's; an issued permit is a document somebody is working under. */
    public function update(User $user, Permit $permit): bool
    {
        return $user->can('ConstructionPermitRequest') && $permit->isDraft();
    }

    /** **The act that authorises high-risk work.** See the class docblock. */
    public function issue(User $user, Permit $permit): bool
    {
        return $user->can('ConstructionPermitIssue') && $permit->isDraft();
    }

    /**
     * Recording acceptance is the requester's side of the transaction, so it rides on the request grant: the gang
     * accepting a permit is not the same act as the site deciding to grant it.
     */
    public function accept(User $user, Permit $permit): bool
    {
        return $user->can('ConstructionPermitRequest')
            && $permit->status === Permit::STATUS_ISSUED
            && $permit->accepted_at === null;
    }

    /**
     * Suspending stops work, and anybody who can see a reason to should be able to.
     *
     * Deliberately the wider grant: a permit that can only be suspended by whoever issued it is a permit that stays live
     * while somebody looks for them.
     */
    public function suspend(User $user, Permit $permit): bool
    {
        return $user->can('ConstructionPermitRequest') && $permit->status === Permit::STATUS_ISSUED;
    }

    /** Resuming is authorising again, so it sits with issuing. */
    public function resume(User $user, Permit $permit): bool
    {
        return $user->can('ConstructionPermitIssue') && $permit->isSuspended();
    }

    /** An extension is a fresh authorisation, so it needs the issuing grant to be issued in turn. */
    public function extend(User $user, Permit $permit): bool
    {
        return $user->can('ConstructionPermitRequest') && ! $permit->isClosed();
    }

    /** The close-out asserts the area was walked and made safe, which is the issuer's assertion. */
    public function close(User $user, Permit $permit): bool
    {
        return $user->can('ConstructionPermitIssue') && ! $permit->isClosed();
    }

    public function cancel(User $user, Permit $permit): bool
    {
        return $user->can('ConstructionPermitRequest') && ! $permit->isClosed();
    }

    /** No delete. A permit is the record of what was authorised, and an insurer asks for it years later. */
    public function delete(User $user, Permit $permit): bool
    {
        return false;
    }
}
