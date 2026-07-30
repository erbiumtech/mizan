<?php

namespace App\Modules\Core\Filament\Resources\Users\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\Users\UserResource;
use App\Modules\Employees\Models\Employee;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = UserResource::class;

    /**
     * Parity with Nova User::afterCreate() — assign the Employee role and
     * auto-create the linked Employee record.
     */
    protected function afterCreate(): void
    {
        $user = $this->record;

        // Roles chosen on the form (for the current company); default to Employee.
        $roles = array_filter((array) ($this->data['roles'] ?? []));
        $user->syncRoles($roles ?: ['Employee']);

        Employee::create([
            'user_id' => $user->id,
            'employee_id' => 'EMP-'.$user->id,
            'is_active' => 1,
        ]);
    }
}
