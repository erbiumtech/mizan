<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        Schema::disableForeignKeyConstraints();

        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            FiscalYearSeeder::class,
            SalarySlabSeeder::class,
            BankSeeder::class,
            EmployeeSeeder::class,
            EmployeeSettingSeeder::class,
            ChartOfAccountsSeeder::class,
            TransactionTypeSeeder::class,
            CompanyBankAccountSeeder::class,
            BeneficiarySeeder::class,
            AccountSeeder::class,
            JournalEntrySeeder::class,
            PayslipSeeder::class,
            FixedAssetSeeder::class,
            PettyCashSeeder::class,
            InventorySeeder::class,
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

        Schema::enableForeignKeyConstraints();
    }
}
