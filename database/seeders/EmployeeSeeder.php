<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Employee;
use Illuminate\Support\Facades\Hash;

class EmployeeSeeder extends Seeder
{
    public function run()
    {
        $employees = [
            ['name' => '[scrubbed]', 'email' => '[scrubbed]'],
            ['name' => '[scrubbed]', 'email' => '[scrubbed]'],
            ['name' => '[scrubbed]', 'email' => '[scrubbed]'],
            ['name' => '[scrubbed]', 'email' => '[scrubbed]'],
            ['name' => '[scrubbed]', 'email' => '[scrubbed]'],
            ['name' => '[scrubbed]', 'email' => '[scrubbed]'],
            ['name' => '[scrubbed]', 'email' => '[scrubbed]'],
            ['name' => 'Umer Farooq', 'email' => 'ufarooq@erbium.ch'],
            ['name' => 'Nadeem Yahya', 'email' => '[scrubbed]'],
            ['name' => 'Arooj Fatima', 'email' => '[scrubbed]'],
        ];

        foreach ($employees as $emp) {

            $user = User::firstOrCreate(
                ['email' => $emp['email']],
                [
                    'name' => $emp['name'],
                    'password' => Hash::make('password123'),
                ]
            );


            if (method_exists($user, 'syncRoles')) {
                $user->syncRoles(['Employee']);
            }

            Employee::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'employee_id' => 'EMP-' . $user->id,
                    'is_active' => 1,
                ]
            );
        }
    }
}
