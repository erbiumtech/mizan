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
            ['name' => 'Huma Javed', 'email' => 'hjaved@erbium.ch'],
            ['name' => 'Muhammad AbuBakar', 'email' => 'mbakar@erbium.ch'],
            ['name' => 'Rashid Bukhari', 'email' => 'rbukhari@erbium.ch'],
            ['name' => 'Abdul Wahab', 'email' => 'awahab@erbium.ch'],
            ['name' => 'Muhammad Mujahid', 'email' => 'mmujahid@erbium.ch'],
            ['name' => 'Hammad Arshad', 'email' => 'harshad@erbium.ch'],
            ['name' => 'Nabeel Ahmad', 'email' => 'nahmad@erbium.ch'],
            ['name' => 'Umer Farooq', 'email' => 'ufarooq@erbium.ch'],
            ['name' => 'Nadeem Yahya', 'email' => 'nyahya@erbium.ch'],
            ['name' => 'Arooj Fatima', 'email' => 'arooj.fatima@erbium4sure.onmicrosoft.com'],
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
                    'gender' => 'Male',
                    'is_active' => 1,
                ]
            );
        }
    }
}
