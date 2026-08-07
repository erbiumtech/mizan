<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Baseline reference data for a newly provisioned personal account.
 *
 * The business equivalent is TenantBaselineSeeder.
 *
 * It keeps the fiscal years (the tax year is the same July-June), currencies
 * (the books need to know what they are kept in) and the bank list, and swaps in
 * a personal chart of accounts, personal spending categories and the individual
 * tax brackets.
 *
 * What it drops is payroll's salary slabs, since a personal account does not run
 * payroll, and the business chart and its supplier-facing transaction types.
 */
class PersonalBaselineSeeder extends Seeder
{
    /** @return array<int, class-string> */
    public static function seeders(): array
    {
        return [
            FiscalYearSeeder::class,
            CurrencySeeder::class,
            PersonalChartOfAccountsSeeder::class,
            // A person has a bank account, and the moment they record one — or a
            // beneficiary they pay — the bank list has to be there. Left out at
            // first on the reasoning that a household is not a business, which
            // confused "does not run payroll" with "does not use a bank".
            BankSeeder::class,
            // Their own categories, mapped to the personal chart. NOT
            // TransactionTypeSeeder: those are keyed to the business chart's
            // codes, which mean different things here.
            PersonalTransactionTypeSeeder::class,
            TaxScheduleSeeder::class,
        ];
    }

    public function run(): void
    {
        $this->call(static::seeders());
    }
}
