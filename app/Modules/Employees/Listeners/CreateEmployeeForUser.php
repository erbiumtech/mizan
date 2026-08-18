<?php

namespace App\Modules\Employees\Listeners;

use App\Events\UserCreated;
use App\Modules\Employees\Models\Employee;

/**
 * Give a new user their employee record.
 *
 * This ran inside `CreateUser::afterCreate()`, which is Core creating an Employee — the exact shape §9
 * inverts. The page now announces that it made a user and this module decides what that means, so a
 * company without Employees creates a user account and nothing else.
 *
 * Idempotent, because an event can be dispatched twice and an employee cannot: the unique thing here is
 * the user, so a second run must not produce a second record.
 */
class CreateEmployeeForUser
{
    public function handle(UserCreated $event): void
    {
        $user = $event->user;

        Employee::firstOrCreate(
            ['user_id' => $user->getKey()],
            ['employee_id' => 'EMP-'.$user->getKey(), 'is_active' => 1],
        );
    }
}
