<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\LeadConversion;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('LeadView');
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->hasPermissionTo('LeadView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('LeadCreate');
    }

    /**
     * A converted lead is frozen.
     *
     * It is the origin record of a customer that now exists in Invoicing, and editing
     * it afterwards makes the two disagree about who that customer was — while the
     * Contact, which is the one the ledger bills, stays as it was. Correct the Contact.
     */
    public function update(User $user, Lead $lead): bool
    {
        return $user->hasPermissionTo('LeadUpdate') && ! $lead->isConverted();
    }

    /** Never once converted: deleting it would orphan the customer's origin. */
    public function delete(User $user, Lead $lead): bool
    {
        return $user->hasPermissionTo('LeadDelete') && ! $lead->isConverted();
    }

    /**
     * Converting is its own permission, and additionally needs the module that gives
     * it somewhere to go.
     *
     * Both halves matter. The permission is a decision about who may create a customer;
     * the module check is why the button is absent rather than broken at a company that
     * never bought Invoicing.
     */
    public function convert(User $user, Lead $lead): bool
    {
        return $user->hasPermissionTo('LeadConvert')
            && $lead->isOpen()
            && app(LeadConversion::class)->isAvailable();
    }
}
