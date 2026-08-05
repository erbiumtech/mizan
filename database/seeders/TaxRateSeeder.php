<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Invoicing\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * The rates a Pakistani company starts from.
 *
 * Deliberately two, not a table of every provincial rate: the standard federal one
 * and zero-rated, because zero-rated is not the same as untaxed — a return has to
 * show that a sale was made at 0%, not that no tax question arose.
 *
 * Both post to 2150 Sales Tax Payable. Give a rate its own account only if you file
 * it separately from that one.
 */
class TaxRateSeeder extends Seeder
{
    public function run(): void
    {
        $account = Account::where('code', TaxRate::DEFAULT_ACCOUNT_CODE)->value('id');

        $rates = [
            ['name' => 'GST 18%', 'code' => 'GST', 'rate' => 18, 'is_default' => true],
            ['name' => 'Zero-rated', 'code' => 'ZR', 'rate' => 0, 'is_default' => false],
        ];

        foreach ($rates as $rate) {
            TaxRate::updateOrCreate(
                ['name' => $rate['name']],
                $rate + ['account_id' => $account, 'is_active' => true],
            );
        }

        $this->command?->info('Seeded '.count($rates).' tax rates. Confirm the rate your invoices actually charge.');
    }
}
