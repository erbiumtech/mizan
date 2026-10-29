<?php

namespace App\Modules\Lifecycle\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Lifecycle\Models\EmployeeDocument;

/**
 * Employee documents get their own group rather than borrowing the checklist's.
 *
 * They are among the most sensitive records this application holds — a passport scan, a
 * visa, a degree certificate — and the Employee role holds none of it. Somebody who can
 * tick off an onboarding task should not thereby be able to read everyone's passports.
 */
class EmployeeDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('EmployeeDocumentView');
    }

    public function view(User $user, EmployeeDocument $document): bool
    {
        return $user->hasPermissionTo('EmployeeDocumentView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('EmployeeDocumentCreate');
    }

    public function update(User $user, EmployeeDocument $document): bool
    {
        return $user->hasPermissionTo('EmployeeDocumentUpdate');
    }

    /**
     * Who may accept or refuse a scan — docs/saas-launch-plan.md §14.8.
     *
     * The same right as editing the document, deliberately: somebody trusted to change
     * what a document says is trusted to say whether it is genuine, and a separate
     * permission for it would be one more thing a small company has to notice exists.
     *
     * Not the person it is about, even where they may edit their own: a document
     * nobody but its owner has looked at has not been verified by anybody.
     */
    public function verify(User $user, EmployeeDocument $document): bool
    {
        return $user->can('EmployeeDocumentUpdate') && $document->employee?->user_id !== $user->getKey();
    }

    public function delete(User $user, EmployeeDocument $document): bool
    {
        return $user->hasPermissionTo('EmployeeDocumentDelete');
    }
}
