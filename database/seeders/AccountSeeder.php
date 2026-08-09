<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    /**
     * Common operating accounts beyond the payroll chart
     * (ChartOfAccountsSeeder must run first for the group headers).
     */
    public function run()
    {
        $extra = [
            '1000' => [
                ['code' => '1300', 'name' => 'Accounts Receivable', 'type' => 'asset'],
                ['code' => '1400', 'name' => 'Office Equipment', 'type' => 'asset'],
            ],
            '2000' => [
                ['code' => '2400', 'name' => 'Accounts Payable', 'type' => 'liability'],
            ],
            '4000' => [
                ['code' => '4200', 'name' => 'Consulting Revenue', 'type' => 'income'],
            ],
            '5000' => [
                ['code' => '5700', 'name' => 'Rent Expense', 'type' => 'expense'],
                ['code' => '5800', 'name' => 'Utilities Expense', 'type' => 'expense'],
                ['code' => '5900', 'name' => 'Office Supplies Expense', 'type' => 'expense'],
            ],
        ];

        foreach ($extra as $parentCode => $accounts) {
            $parent = Account::where('code', $parentCode)->first();

            if (! $parent) {
                $this->command?->warn("Group account {$parentCode} missing; run ChartOfAccountsSeeder first.");

                continue;
            }

            foreach ($accounts as $data) {
                Account::updateOrCreate(
                    ['code' => $data['code']],
                    $data + ['parent_id' => $parent->id]
                );
            }
        }
    }
}
