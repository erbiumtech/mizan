<?php

namespace Tests;

use App\Models\FiscalYear;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SalarySlabSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class AccountingTestCase extends TestCase
{
    use RefreshDatabase;

    protected FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionSeeder::class,
            RoleSeeder::class,
            FiscalYearSeeder::class,
            SalarySlabSeeder::class,
            ChartOfAccountsSeeder::class,
            AccountSeeder::class,
        ]);

        $this->fiscalYear = FiscalYear::where('name', '2026-2027')->firstOrFail();
    }

    protected function makeUser(string $role, string $email): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => 1,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
