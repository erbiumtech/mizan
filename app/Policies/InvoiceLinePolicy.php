<?php

namespace App\Policies;

use App\Models\InvoiceLine;
use App\Models\User;

/**
 * Invoice lines are part of an invoice, so they follow the invoice permissions
 * rather than carrying their own. Without this the resource had no policy at
 * all, which Filament treats as "allowed" — every role could read them.
 */
class InvoiceLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('InvoiceView');
    }

    public function view(User $user, InvoiceLine $invoiceLine): bool
    {
        return $user->hasPermissionTo('InvoiceView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('InvoiceCreate');
    }

    public function update(User $user, InvoiceLine $invoiceLine): bool
    {
        return $user->hasPermissionTo('InvoiceUpdate');
    }

    public function delete(User $user, InvoiceLine $invoiceLine): bool
    {
        return $user->hasPermissionTo('InvoiceUpdate');
    }
}
