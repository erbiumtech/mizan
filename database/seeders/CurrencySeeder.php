<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * The base currency, and the one currency this company actually quotes in.
 *
 * PKR is the base: it is what every amount already posted means, and that is not a
 * setting so much as a fact about the existing ledger.
 */
class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        Currency::updateOrCreate(
            ['code' => 'PKR'],
            ['name' => 'Pakistani Rupee', 'symbol' => 'Rs', 'decimals' => 2, 'is_base' => true, 'is_active' => true],
        );

        Currency::updateOrCreate(
            ['code' => 'EUR'],
            ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_base' => false, 'is_active' => true],
        );

        // The platform bills foreign customers in USD or EUR (docs/saas-launch-plan.md
        // §3.1), and the operator tenant's chart is this one.
        Currency::updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'is_base' => false, 'is_active' => true],
        );

        $this->command?->info('Seeded PKR (base), EUR and USD. Record a rate before posting anything in a foreign currency.');
    }
}
