<?php

namespace App\Modules\Invoicing\Policies;

use App\Modules\Invoicing\Models\Contact;
use App\Modules\Core\Models\User;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ContactView');
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->hasPermissionTo('ContactView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ContactCreate');
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->hasPermissionTo('ContactUpdate');
    }

    public function delete(User $user, Contact $contact): bool
    {
        if (! $user->hasPermissionTo('ContactDelete')) {
            return false;
        }

        // Contacts with invoice history must not be deleted.
        return ! $contact->invoices()->exists();
    }
}
