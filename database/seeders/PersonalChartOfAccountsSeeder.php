<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Seeder;

/**
 * The chart of accounts a personal account starts with.
 *
 * The business chart (ChartOfAccountsSeeder) is built around receivables,
 * payables, sales tax and retained earnings — most of which mean nothing to a
 * household, and all of which are noise on a screen where somebody is trying to
 * record what they spent on groceries.
 *
 * This is the same five account types over the same ledger, just named for how
 * a person actually thinks about their money. Domestic staff wages are their own
 * expense line because paying the cook is the household's payroll, recorded as
 * an ordinary expense rather than through payslips.
 *
 * Two codes are load bearing and match the business chart on purpose:
 * Opening Balance Equity (3300) and Retained Earnings (3200) are looked up by
 * code by the accounting module — see Account::OPENING_BALANCE_EQUITY_CODE —
 * and a personal account that lacks them would break the moment somebody set an
 * opening balance or closed a year.
 */
class PersonalChartOfAccountsSeeder extends Seeder
{
    /** code, name, type. */
    private const ACCOUNTS = [
        // What you have.
        ['1000', 'Cash in Hand', 'asset'],
        ['1100', 'Bank Account', 'asset'],
        ['1200', 'Savings', 'asset'],
        ['1400', 'Property', 'asset'],
        ['1450', 'Vehicles', 'asset'],
        ['1500', 'Investments', 'asset'],

        // What you owe.
        ['2000', 'Loans', 'liability'],
        ['2100', 'Credit Card', 'liability'],
        ['2200', 'Amounts Owed to Others', 'liability'],

        // Equity. Both codes are relied on by the accounting module.
        ['3200', 'Retained Earnings', 'equity'],
        ['3300', 'Opening Balance Equity', 'equity'],

        // What comes in.
        ['4000', 'Salary', 'income'],
        ['4100', 'Business Income', 'income'],
        ['4200', 'Rental Income', 'income'],
        ['4300', 'Profit on Investments', 'income'],
        ['4900', 'Other Income', 'income'],

        // What goes out.
        ['5100', 'Food & Groceries', 'expense'],
        ['5200', 'Rent', 'expense'],
        ['5300', 'Education', 'expense'],
        ['5350', 'Domestic Staff Wages', 'expense'],
        ['5400', 'Utilities', 'expense'],
        ['5500', 'Transport & Fuel', 'expense'],
        ['5600', 'Medical', 'expense'],
        ['5700', 'Household & Maintenance', 'expense'],
        ['5800', 'Family & Gifts', 'expense'],
        ['5900', 'Other Expenses', 'expense'],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as [$code, $name, $type]) {
            Account::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'is_active' => true,
                    'allow_manual_entry' => true,
                ],
            );
        }
    }
}
