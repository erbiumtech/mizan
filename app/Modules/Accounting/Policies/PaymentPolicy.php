<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('PaymentView');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->hasPermissionTo('PaymentView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('PaymentCreate');
    }

    public function update(User $user, Payment $payment): bool
    {
        return $user->hasPermissionTo('PaymentUpdate');
    }

    public function delete(User $user, Payment $payment): bool
    {
        // Only drafts may be deleted.
        return $user->hasPermissionTo('PaymentDelete') && $payment->status === Payment::STATUS_DRAFT;
    }
}
