<?php

namespace Database\Seeders\Production;

use App\Modules\Core\Models\Company;
use App\Modules\Employees\Models\Employee;
use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * REAL PRODUCTION DATA — kept out of the default `db:seed` run.
 *
 * The seeders in Database\Seeders create dummy data so a fresh install (or a
 * demo, or a developer's machine) never carries real people, salaries or trading
 * partners. The genuine values live here instead, and only run when named
 * explicitly:
 *
 *     php artisan db:seed --class="Database\Seeders\Production\RealEmployeeSeeder"
 *
 * Tenant-scoped: a company must be current, so run this from a context that has
 * made one current (or via `php artisan tenants:artisan`).
 */
class RealEmployeeSeeder extends Seeder
{
    public function run()
    {
        // 'role'    — the company role assigned to the user.
        // 'manager' — email of the employee this person reports to (manager_id).
        $employees = [
            ['name' => 'Huma Javed', 'email' => 'hjaved@erbium.ch', 'role' => 'Employee', 'status' => 1], // Manager
            ['name' => 'Muhammad AbuBakar', 'email' => 'mbakar@erbium.ch', 'role' => 'Employee', 'status' => 1], // Manager
            ['name' => 'Rashid Bukhari', 'email' => 'rbukhari@erbium.ch', 'role' => 'Employee', 'status' => 1], // Manager
            ['name' => 'Abdul Wahab', 'email' => 'awahab@erbium.ch', 'role' => 'Employee', 'status' => 1], // Manager
            ['name' => 'Muhammad Mujahid', 'email' => 'mmujahid@erbium.ch', 'role' => 'Employee', 'status' => 1], // Manager
            ['name' => 'Hammad Arshad', 'email' => 'harshad@erbium.ch', 'role' => 'Employee', 'status' => 1], // Manager
            ['name' => 'Nabeel Ahmad', 'email' => 'nahmad@erbium.ch', 'role' => 'Employee', 'status' => 1], // Manager
            ['name' => 'Umer Farooq', 'email' => 'ufarooq@erbium.ch', 'role' => 'Employee', 'status' => 1], // Manager
            ['name' => 'Nadeem Yahya', 'email' => 'nyahya@erbium.ch', 'role' => 'Employee', 'status' => 1], // Employee
            ['name' => 'Arooj Fatima', 'email' => 'arooj.fatima@erbium.ch', 'role' => 'Employee', 'status' => 1], // Employee
            ['name' => 'Fatima Tauqeer', 'email' => 'fatimamohid03@gmail.com', 'role' => 'Employee', 'status' => 1, 'manager' => 'nahmad@erbium.ch'], // Employee — reports to Nabeel Ahmad
            ['name' => 'Muhammad Hamza', 'email' => 'iamhamzaaiofficial@gmail.com', 'role' => 'Employee', 'status' => 1, 'manager' => 'harshad@erbium.ch'], // Employee — reports to Hammad Arshad
            ['name' => 'Sawera Javed', 'email' => 'sawerajaved2318@gmail.com', 'role' => 'Employee', 'status' => 1, 'manager' => 'awahab@erbium.ch'], // Employee — reports to Abdul Wahab
            ['name' => 'Maryam Zahid', 'email' => 'maryamzzahid987@gmail.com', 'role' => 'Employee', 'status' => 1, 'manager' => 'mbakar@erbium.ch'], // Employee — reports to Muhammad AbuBakar
            ['name' => 'Muhammad Usman', 'email' => 'usmanqaisrani555@gmail.com', 'role' => 'Employee', 'status' => 1, 'manager' => 'ufarooq@erbium.ch'], // Employee — reports to Umer Farooq
            ['name' => 'Syed Rahat Fatima', 'email' => 'rahatrashid78600@gmail.com', 'role' => 'Employee', 'status' => 1, 'manager' => 'rbukhari@erbium.ch'], // Employee — reports to Rashid Bukhari

            // Support staff, on the payroll and on the salaries sheet but with no
            // company mailbox. The addresses below are placeholders on the reserved
            // `example.test` domain, so a notification can never reach a real inbox
            // that happens to match a guess. Replace them with real addresses if
            // either of these two ever needs to sign in — everything else about
            // them (their package, payslips, and the client bill) works either way.
            ['name' => 'Muhammad Abid', 'email' => 'muhammad.abid@example.test', 'role' => 'Employee', 'status' => 1], // Cook, helper and office boy
            ['name' => 'Ahmad Ishtiaq', 'email' => 'ahmad.ishtiaq@example.test', 'role' => 'Employee', 'status' => 1], // Internee
        ];

        /** @var array<string, Employee> $created keyed by email, to resolve managers */
        $created = [];

        // Employees are seeded into whichever company is current; their login
        // users live in the landlord database and need explicit membership of
        // that company to be able to reach it (see User::canAccessTenant).
        $company = Company::current();

        foreach ($employees as $emp) {
            $user = User::firstOrCreate(
                ['email' => $emp['email']],
                [
                    'name' => $emp['name'],
                    'password' => Hash::make('password123'),
                    'status' => 1,
                ]
            );

            // users.status defaults to 0 (inactive), so activate on re-seed too.
            if ((int) $user->status !== 1) {
                $user->forceFill(['status' => 1])->save();
            }

            if (method_exists($user, 'syncRoles')) {
                $user->syncRoles([$emp['role']]);
            }

            $company?->users()->syncWithoutDetaching([$user->id]);

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
