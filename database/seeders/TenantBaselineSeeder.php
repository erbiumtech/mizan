<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Baseline reference data every newly provisioned company needs in its own
 * tenant database. Runs against whichever tenant is currently active, so it
 * must only be invoked while a tenant is "current".
 */
class TenantBaselineSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FiscalYearSeeder::class,
            ChartOfAccountsSeeder::class,
            TransactionTypeSeeder::class,
            SalarySlabSeeder::class,
            BankSeeder::class,
        ]);
    }
}
