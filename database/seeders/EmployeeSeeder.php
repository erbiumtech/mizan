<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EmployeeSeeder extends Seeder
{
    public function run()
    {
        // 'role'    — the company role assigned to the user.
        // 'manager' — email of the employee this person reports to (manager_id).
        $employees = [
            ['name' => '[scrubbed]', 'email' => '[scrubbed]', 'role' => 'Manager'], // Manager
            ['name' => '[scrubbed]', 'email' => '[scrubbed]', 'role' => 'Manager'], // Manager
            ['name' => '[scrubbed]', 'email' => '[scrubbed]', 'role' => 'Manager'], // Manager
            ['name' => '[scrubbed]', 'email' => '[scrubbed]', 'role' => 'Manager'], // Manager
            ['name' => '[scrubbed]', 'email' => '[scrubbed]', 'role' => 'Manager'], // Manager
            ['name' => '[scrubbed]', 'email' => '[scrubbed]', 'role' => 'Manager'], // Manager
            ['name' => '[scrubbed]', 'email' => '[scrubbed]', 'role' => 'Manager'], // Manager
            ['name' => 'Umer Farooq', 'email' => 'ufarooq@erbium.ch', 'role' => 'Manager'], // Manager
            ['name' => 'Nadeem Yahya', 'email' => '[scrubbed]', 'role' => 'Employee'], // Employee
            ['name' => 'Arooj Fatima', 'email' => '[scrubbed]', 'role' => 'Employee'], // Employee
            ['name' => '[scrubbed]', 'email' => '[scrubbed]', 'role' => 'Employee', 'manager' => '[scrubbed]'], // Employee — reports to [scrubbed]
            ['name' => '[scrubbed]', 'email' => '[scrubbed]', 'role' => 'Employee', 'manager' => '[scrubbed]'], // Employee — reports to [scrubbed]
            ['name' => '[scrubbed]', 'email' => '[scrubbed]', 'role' => 'Employee', 'manager' => '[scrubbed]'], // Employee — reports to [scrubbed]
            ['name' => '[scrubbed]', 'email' => '[scrubbed]', 'role' => 'Employee', 'manager' => '[scrubbed]'], // Employee — reports to [scrubbed]
            ['name' => '[scrubbed]', 'email' => '[scrubbed]', 'role' => 'Employee', 'manager' => 'ufarooq@erbium.ch'], // Employee — reports to Umer Farooq
            ['name' => '[scrubbed]', 'email' => '[scrubbed]', 'role' => 'Employee', 'manager' => '[scrubbed]'], // Employee — reports to [scrubbed]
        ];

        /** @var array<string, Employee> $created keyed by email, to resolve managers */
        $created = [];

        foreach ($employees as $emp) {
            $user = User::firstOrCreate(
                ['email' => $emp['email']],
                [
                    'name' => $emp['name'],
                    'password' => Hash::make('password123'),
                ]
            );

            if (method_exists($user, 'syncRoles')) {
                $user->syncRoles([$emp['role']]);
            }

            $created[$emp['email']] = Employee::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'employee_id' => 'EMP-'.$user->id,
                    'gender' => 'Male',
                    'is_active' => 1,
                ]
            );
        }

        // Second pass: managers must exist before they can be pointed at.
        foreach ($employees as $emp) {
            $manager = $created[$emp['manager'] ?? ''] ?? null;

            if ($manager && $created[$emp['email']]->manager_id !== $manager->id) {
                $created[$emp['email']]->forceFill(['manager_id' => $manager->id])->save();
            }
        }
    }
}
