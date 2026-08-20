<?php

namespace App\Modules\ConstructionField\Policies;

use App\Modules\ConstructionField\Models\DelayEvent;
use App\Modules\Core\Models\User;

/**
 * Who raises a delay event, and who determines it — §13.
 *
 * **Raising is site's, and this is the third create grant site staff hold in this suite** after the requisition, the
 * goods receipt and the site sheet. §13's clock only works if events are raised early and often, and the people who
 * watch an access being blocked or a drawing arriving late are on site. An event they cannot record is an event nobody
 * records — and the failure is silent.
 *
 * **`Determine` is its own permission** because awarding days moves the completion date and decides whether liquidated
 * damages can be levied at all. One person raising and determining their own claims is the segregation this suite keeps
 * everywhere else.
 *
 * **There is no delete**, only withdrawal with a reason: the reference series has no gaps, because a missing number is
 * a question at adjudication and "withdrawn on the 14th" is an answer.
 */
class DelayEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionDelayView');
    }

    public function view(User $user, DelayEvent $event): bool
    {
        return $user->can('ConstructionDelayView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionDelayUpdate');
    }

    /** Editable until it is determined: after that the row is the record of what was decided. */
    public function update(User $user, DelayEvent $event): bool
    {
        return $user->can('ConstructionDelayUpdate') && ! $event->isClosed();
    }

    /** Serving notice is the act the whole section exists for, so it sits with whoever maintains the register. */
    public function notify(User $user, DelayEvent $event): bool
    {
        return $user->can('ConstructionDelayUpdate') && ! $event->noticeGiven() && ! $event->isClosed();
    }

    public function submitParticulars(User $user, DelayEvent $event): bool
    {
        return $user->can('ConstructionDelayUpdate') && $event->noticeGiven() && ! $event->isClosed();
    }

    public function determine(User $user, DelayEvent $event): bool
    {
        return $user->can('ConstructionDelayDetermine') && ! $event->isClosed();
    }

    public function withdraw(User $user, DelayEvent $event): bool
    {
        return $user->can('ConstructionDelayUpdate') && ! $event->isClosed();
    }
}
