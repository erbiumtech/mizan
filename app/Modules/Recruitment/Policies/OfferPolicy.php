<?php

namespace App\Modules\Recruitment\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Recruitment\Models\Offer;

/**
 * Applicants, their applications, interviews and offers share ONE permission group.
 *
 * They are one pipeline, and nobody grants "may see an applicant but not their
 * interview". More importantly, splitting them would invite a role that can read CVs
 * without being trusted with the rest — and a CV is the most sensitive record here.
 *
 * The Employee role holds none of this.
 */
class OfferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ApplicantView');
    }

    public function view(User $user, Offer $record): bool
    {
        return $user->hasPermissionTo('ApplicantView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ApplicantCreate');
    }

    public function update(User $user, Offer $record): bool
    {
        return $user->hasPermissionTo('ApplicantUpdate');
    }

    public function delete(User $user, Offer $record): bool
    {
        return $user->hasPermissionTo('ApplicantDelete');
    }

    /**
     * Hiring is its own permission, and additionally needs the module it creates into.
     *
     * Both halves matter: creating an employee — and with them a salary package — is a
     * bigger decision than moving somebody through a pipeline, and without `employees`
     * the action is absent rather than broken at a company hiring its very first person.
     */
    public function hire(User $user, Offer $record): bool
    {
        return $user->hasPermissionTo('OfferHire')
            && $record->isOpen()
            && app(\App\Modules\Recruitment\Services\HireService::class)->isAvailable();
    }
}
