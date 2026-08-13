<?php

namespace App\Modules\Support\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Support\Models\TicketReply;

/**
 * Tickets, their categories and their replies share one group.
 *
 * Answering a ticket and reading it are the same job. Categories carry the SLA commitments, so
 * changing one changes what the company has promised — which is why Update is not the same
 * grant as replying.
 */
class TicketReplyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('TicketView');
    }

    public function view(User $user, TicketReply $record): bool
    {
        return $user->hasPermissionTo('TicketView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('TicketCreate');
    }

    public function update(User $user, TicketReply $record): bool
    {
        return $user->hasPermissionTo('TicketUpdate');
    }

    public function delete(User $user, TicketReply $record): bool
    {
        return $user->hasPermissionTo('TicketDelete');
    }
}
