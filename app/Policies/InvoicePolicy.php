<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('InvoiceView');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->hasPermissionTo('InvoiceView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('InvoiceCreate');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        // Issued invoices are ledger-backed and immutable; void or reissue.
        return $user->hasPermissionTo('InvoiceUpdate') && $invoice->isDraft();
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->hasPermissionTo('InvoiceVoid') && $invoice->isDraft();
    }

    /**
     * Issued invoices are not updatable, but Issue / Record Payment /
     * Void actions must still run; each action gates its own permission.
     */
    public function runAction(User $user, Invoice $invoice): bool
    {
        return $user->hasPermissionTo('InvoiceIssue')
            || $user->hasPermissionTo('InvoicePay')
            || $user->hasPermissionTo('InvoiceVoid');
    }
}
