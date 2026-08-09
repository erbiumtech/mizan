<?php

namespace App\Modules\Invoicing\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Invoicing\Models\TaxRate;

/**
 * Governed by the invoice permissions: a tax rate is part of how an invoice is
 * priced, and whoever may raise one may say what tax it carries. There is no
 * separate delete permission in that group — InvoiceVoid covers undoing an issued
 * document, and a rate is never deleted once charged anyway.
 */
class TaxRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('InvoiceView');
    }

    public function view(User $user, TaxRate $rate): bool
    {
        return $user->hasPermissionTo('InvoiceView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('InvoiceCreate');
    }

    public function update(User $user, TaxRate $rate): bool
    {
        return $user->hasPermissionTo('InvoiceUpdate');
    }

    /**
     * Only while nothing has been charged at it. A rate that has been applied is
     * the record of what an issued invoice charged, and deleting it would leave
     * that invoice unable to say why its tax was what it was — deactivating is what
     * stops it being offered.
     */
    public function delete(User $user, TaxRate $rate): bool
    {
        return $user->hasPermissionTo('InvoiceUpdate') && ! $rate->lines()->exists();
    }
}
