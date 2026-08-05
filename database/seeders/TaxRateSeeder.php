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
            $existing = TaxRate::where('name', $rate['name'])->first();

            if (! $existing) {
                TaxRate::create($rate + ['account_id' => $account, 'is_active' => true]);

                continue;
            }

            /*
             * Which rate an invoice charges by default, and whether a rate is offered at
             * all, are decisions about this company — not facts about Pakistani tax. They
             * are set when the rate is first created and never re-asserted afterwards.
             *
             * Re-running this seeder is how a company picks up a rate added to the code,
             * and it must not quietly put 18% back onto every invoice line of a company
             * that deliberately turned it off — an export client charged sales tax by a
             * seeder is not a mistake anybody would look for.
             */
            $existing->update([
                'code' => $rate['code'],
                'rate' => $rate['rate'],
                'account_id' => $account,
            ]);
        }

        $this->command?->info('Seeded '.count($rates).' tax rates. Confirm the rate your invoices actually charge.');
    }
}
