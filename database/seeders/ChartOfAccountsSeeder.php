<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    public function run()
    {
        // Payroll-oriented default chart. Parents are group headers
        // (no manual entry); children are the postable leaf accounts.
        $chart = [
            ['code' => '1000', 'name' => 'Assets', 'type' => 'asset', 'allow_manual_entry' => false, 'children' => [
                ['code' => '1100', 'name' => 'Cash / Bank', 'type' => 'asset'],
                ['code' => '1150', 'name' => 'Petty Cash', 'type' => 'asset', 'description' => 'Imprest petty cash box'],
                ['code' => '1200', 'name' => 'Employee Advances', 'type' => 'asset', 'description' => 'Advances paid to employees, recovered via payroll'],
                ['code' => '1250', 'name' => 'Accounts Receivable', 'type' => 'asset', 'description' => 'Amounts owed by customers on issued invoices'],
                ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'description' => 'Stock on hand at cost'],
                ['code' => '1400', 'name' => 'Office Equipment', 'type' => 'asset', 'description' => 'Fixed assets: computers, furniture, hardware'],
                ['code' => '1450', 'name' => 'Vehicles', 'type' => 'asset', 'description' => 'Fixed assets: company vehicles'],
                ['code' => '1500', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'normal_balance' => 'credit', 'description' => 'Contra-asset: credit-normal'],
            ]],
            ['code' => '2000', 'name' => 'Liabilities', 'type' => 'liability', 'allow_manual_entry' => false, 'children' => [
                ['code' => '2100', 'name' => 'Income Tax Payable', 'type' => 'liability', 'description' => 'Withholding tax deducted from salaries, payable to FBR'],
                ['code' => '2150', 'name' => 'Sales Tax Payable', 'type' => 'liability', 'description' => 'Sales tax on invoices (output less input)'],
                ['code' => '2200', 'name' => 'ESI / Health Insurance Payable', 'type' => 'liability'],
                ['code' => '2300', 'name' => 'Salaries Payable', 'type' => 'liability', 'description' => 'Net salaries owed to employees'],
                ['code' => '2400', 'name' => 'Accounts Payable', 'type' => 'liability', 'description' => 'Amounts owed to suppliers on bills'],
            ]],
            ['code' => '3000', 'name' => 'Equity', 'type' => 'equity', 'allow_manual_entry' => false, 'children' => [
                ['code' => '3100', 'name' => 'Owner Equity', 'type' => 'equity'],
                ['code' => '3200', 'name' => 'Retained Earnings', 'type' => 'equity'],
                // The counter-account for opening balances, so bringing a book
                // onto the system is distinguishable from genuine owner
                // contributions. Once every account's opening balance is
                // entered, this should net to zero.
                ['code' => '3300', 'name' => 'Opening Balance Equity', 'type' => 'equity', 'description' => 'Counter-account for account opening balances; nets to zero once the book is fully opened'],
            ]],
            ['code' => '4000', 'name' => 'Income', 'type' => 'income', 'allow_manual_entry' => false, 'children' => [
                ['code' => '4100', 'name' => 'Service Revenue', 'type' => 'income'],
                ['code' => '4200', 'name' => 'Sales Revenue', 'type' => 'income', 'description' => 'Product sales'],
                ['code' => '4300', 'name' => 'Other Income', 'type' => 'income', 'description' => 'Non-product invoice lines'],
                // Exchange differences, one account each rather than a gain account and
                // a loss account: a gain of 100 that becomes a loss of 40 should read as
                // a net 40 loss, not as a gain of 100 beside a loss of 140. A debit
                // balance shows as a negative income line, which is what a loss is.
                ['code' => '4400', 'name' => 'Unrealised Exchange Gain / (Loss)', 'type' => 'income', 'description' => 'Foreign balances retranslated at period end; no money has moved'],
                ['code' => '4450', 'name' => 'Realised Exchange Gain / (Loss)', 'type' => 'income', 'description' => 'The difference between what a foreign amount was booked at and what was actually settled'],
            ]],
            ['code' => '5000', 'name' => 'Expenses', 'type' => 'expense', 'allow_manual_entry' => false, 'children' => [
                ['code' => '5050', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'description' => 'Inventory cost of product sales'],
                ['code' => '5100', 'name' => 'Basic Salary Expense', 'type' => 'expense'],
                ['code' => '5200', 'name' => 'Medical Allowance Expense', 'type' => 'expense'],
                ['code' => '5300', 'name' => 'Petrol Allowance Expense', 'type' => 'expense'],
                ['code' => '5400', 'name' => 'Device Allowance Expense', 'type' => 'expense'],
                ['code' => '5500', 'name' => 'Bonus & Overtime Expense', 'type' => 'expense'],
                ['code' => '5600', 'name' => 'Meal Expense', 'type' => 'expense'],
                ['code' => '5700', 'name' => 'Rent Expense', 'type' => 'expense'],
                ['code' => '5750', 'name' => 'Utilities Expense', 'type' => 'expense'],
                ['code' => '5800', 'name' => 'Fuel & Travel Expense', 'type' => 'expense'],
                ['code' => '5850', 'name' => 'Office Supplies Expense', 'type' => 'expense'],
                ['code' => '5860', 'name' => 'Cleaning Expense', 'type' => 'expense'],
                ['code' => '5900', 'name' => 'Miscellaneous Expense', 'type' => 'expense', 'description' => 'Costs with no more specific account'],
                ['code' => '5990', 'name' => 'Depreciation Expense', 'type' => 'expense'],
                ['code' => '5995', 'name' => 'Loss on Asset Disposal', 'type' => 'expense'],
            ]],
        ];

        foreach ($chart as $parentData) {
            $children = $parentData['children'];
            unset($parentData['children']);

            $parent = Account::updateOrCreate(['code' => $parentData['code']], $parentData);

            foreach ($children as $childData) {
                Account::updateOrCreate(
                    ['code' => $childData['code']],
                    $childData + ['parent_id' => $parent->id]
                );
            }
        }
    }
}
