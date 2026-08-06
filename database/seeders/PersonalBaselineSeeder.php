<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Baseline reference data for a newly provisioned personal account.
 *
 * The business equivalent is TenantBaselineSeeder. This one drops what a
 * household has no use for — the business chart of accounts, transaction types
 * for supplier payments, the bank list used for salary files, and payroll's
 * salary slabs, since a personal account does not run payroll.
 *
 * It keeps the fiscal years (the tax year is the same July-June), currencies
 * (the books need to know what they are kept in), and adds the personal chart
 * of accounts and the individual tax brackets.
 */
class PersonalBaselineSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FiscalYearSeeder::class,
            CurrencySeeder::class,
            PersonalChartOfAccountsSeeder::class,
            TaxScheduleSeeder::class,
        ]);
    }
}
