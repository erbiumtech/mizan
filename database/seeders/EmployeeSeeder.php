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
            ['name' => 'Huma Javed', 'email' => 'hjaved@erbium.ch', 'role' => 'Manager'], // Manager
            ['name' => 'Muhammad AbuBakar', 'email' => 'mbakar@erbium.ch', 'role' => 'Manager'], // Manager
            ['name' => 'Rashid Bukhari', 'email' => 'rbukhari@erbium.ch', 'role' => 'Manager'], // Manager
            ['name' => 'Abdul Wahab', 'email' => 'awahab@erbium.ch', 'role' => 'Manager'], // Manager
            ['name' => 'Muhammad Mujahid', 'email' => 'mmujahid@erbium.ch', 'role' => 'Manager'], // Manager
            ['name' => 'Hammad Arshad', 'email' => 'harshad@erbium.ch', 'role' => 'Manager'], // Manager
            ['name' => 'Nabeel Ahmad', 'email' => 'nahmad@erbium.ch', 'role' => 'Manager'], // Manager
            ['name' => 'Umer Farooq', 'email' => 'ufarooq@erbium.ch', 'role' => 'Manager'], // Manager
            ['name' => 'Nadeem Yahya', 'email' => 'nyahya@erbium.ch', 'role' => 'Employee'], // Employee
            ['name' => 'Arooj Fatima', 'email' => 'arooj.fatima@erbium.ch', 'role' => 'Employee'], // Employee
            ['name' => 'Fatima Tauqeer', 'email' => 'fatimamohid03@gmail.com', 'role' => 'Employee', 'manager' => 'nahmad@erbium.ch'], // Employee — reports to Nabeel Ahmad
            ['name' => 'Muhammad Hamza', 'email' => 'iamhamzaaiofficial@gmail.com', 'role' => 'Employee', 'manager' => 'harshad@erbium.ch'], // Employee — reports to Hammad Arshad
            ['name' => 'Sawera Javed', 'email' => 'sawerajaved2318@gmail.com', 'role' => 'Employee', 'manager' => 'awahab@erbium.ch'], // Employee — reports to Abdul Wahab
            ['name' => 'Maryam Zahid', 'email' => 'maryamzzahid987@gmail.com', 'role' => 'Employee', 'manager' => 'mbakar@erbium.ch'], // Employee — reports to Muhammad AbuBakar
            ['name' => 'Muhammad Usman', 'email' => 'usmanqaisrani555@gmail.com', 'role' => 'Employee', 'manager' => 'ufarooq@erbium.ch'], // Employee — reports to Umer Farooq
            ['name' => 'Syed Rahat Fatima', 'email' => 'rahatrashid78600@gmail.com', 'role' => 'Employee', 'manager' => 'rbukhari@erbium.ch'], // Employee — reports to Rashid Bukhari
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
