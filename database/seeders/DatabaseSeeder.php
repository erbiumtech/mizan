<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run()
    {

        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            FiscalYearSeeder::class,
            SalarySlabSeeder::class,
        ]);

        // Admin User Creation
        $admin = User::firstOrCreate(
            ['email' => 'admin@erbium.tech'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('password'),
                'status' => 1,
            ]
        );

        $admin->assignRole('Administrator');
    }
}
