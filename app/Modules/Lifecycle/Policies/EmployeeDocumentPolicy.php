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

    public function delete(User $user, EmployeeDocument $document): bool
    {
        return $user->hasPermissionTo('EmployeeDocumentDelete');
    }
}
