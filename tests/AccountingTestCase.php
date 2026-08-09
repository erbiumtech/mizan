<?php

namespace Tests;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SalarySlabSeeder;
use Filament\Facades\Filament;
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

        // A user who works in a company is a member of it. `users` is a shared
        // landlord table, so UserResource keeps row-level tenant scoping and that
        // membership is what makes them visible — to the Users list, to an
        // employee's user picker, to an approver lookup. Production attaches on
        // create; a fixture that skips it is testing a state the app cannot reach.
        if ($company = Filament::getTenant() ?? Company::current()) {
            $user->companies()->syncWithoutDetaching([$company->getKey()]);
        }

        return $user;
    }
}
