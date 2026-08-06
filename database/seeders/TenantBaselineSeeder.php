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
            // Without this a company has no currencies at all: nothing to show on the
            // Currencies screen, and no row saying which one its books are kept in.
            CurrencySeeder::class,
            TransactionTypeSeeder::class,
            SalarySlabSeeder::class,
            BankSeeder::class,
            // Tax brackets for the Personal Finance estimate. Reference data
            // shared by everyone in the company, unlike the per-person chart of
            // accounts, which is seeded for a user the first time they open the
            // module.
            TaxScheduleSeeder::class,
        ]);
    }
}
