<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\TransactionType;
use Illuminate\Database\Seeder;

class TransactionTypeSeeder extends Seeder
{
    /**
     * Payment/expense categories, each mapped to its default chart account.
     * Idempotent via code.
     */
    public function run()
    {
        $types = [
            ['code' => 'salary', 'name' => 'Salary', 'account_code' => '5100', 'description' => 'Monthly payroll'],
            ['code' => 'rent', 'name' => 'Rent', 'account_code' => '5700', 'description' => 'Office rent'],
            ['code' => 'food', 'name' => 'Food', 'account_code' => '5600', 'description' => 'Meals & catering'],
            ['code' => 'utilities', 'name' => 'Utilities', 'account_code' => '5750', 'description' => 'Electricity, internet, phone'],
            ['code' => 'fuel', 'name' => 'Fuel', 'account_code' => '5800', 'description' => 'Fuel & travel'],
            ['code' => 'office-supplies', 'name' => 'Office Supplies', 'account_code' => '5850', 'description' => 'Stationery & consumables'],
            ['code' => 'equipment', 'name' => 'Equipment', 'account_code' => '1400', 'description' => 'Fixed asset purchases'],
            ['code' => 'tax-payment', 'name' => 'Tax Payment', 'account_code' => '2100', 'description' => 'FBR withholding tax remittance'],
            ['code' => 'miscellaneous', 'name' => 'Miscellaneous', 'account_code' => null, 'description' => 'Everything else'],
        ];

        foreach ($types as $type) {
            $account = $type['account_code']
                ? Account::where('code', $type['account_code'])->first()
                : null;

            TransactionType::updateOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'account_id' => $account?->id,
                    'description' => $type['description'],
                ]
            );
        }

        $this->command?->info('Seeded '.count($types).' transaction types.');
    }
}
