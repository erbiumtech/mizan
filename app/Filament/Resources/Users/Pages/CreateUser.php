<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\Employee;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Parity with Nova User::afterCreate() — assign the Employee role and
     * auto-create the linked Employee record.
     */
    protected function afterCreate(): void
    {
        $user = $this->record;

        $user->syncRoles(['Employee']);

        Employee::create([
            'user_id' => $user->id,
            'employee_id' => 'EMP-'.$user->id,
            'is_active' => 1,
        ]);
    }
}
