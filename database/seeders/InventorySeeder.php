<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    /**
     * Demo products across all three valuation methods, with a
     * purchase/sale history through elapsed months so FIFO vs LIFO vs
     * average produce visibly different COGS. Idempotent via sku.
     */
    public function run()
    {
        $fiscalYear = FiscalYear::where('is_active', true)->first();

        if (! $fiscalYear || ! Account::where('code', '1300')->exists()) {
            $this->command?->warn('Needs an active fiscal year and account 1300; run ChartOfAccountsSeeder first.');

            return;
        }

        $products = [
            ['sku' => 'LAP-DEV-01', 'name' => 'Developer Laptop (Resale)', 'unit' => 'pcs', 'valuation_method' => 'fifo', 'reorder_level' => 2],
            ['sku' => 'CBL-HDMI-01', 'name' => 'HDMI Cable 2m', 'unit' => 'pcs', 'valuation_method' => 'lifo', 'reorder_level' => 10],
            ['sku' => 'PPR-A4-01', 'name' => 'A4 Paper Ream', 'unit' => 'ream', 'valuation_method' => 'average', 'reorder_level' => 20],
            ['sku' => 'TNR-HP-01', 'name' => 'HP Toner Cartridge', 'unit' => 'pcs', 'valuation_method' => 'fifo', 'reorder_level' => 3],
        ];

        $created = 0;

        foreach ($products as $data) {
            $product = Product::firstOrCreate(['sku' => $data['sku']], $data);

            if ($product->wasRecentlyCreated) {
                $created++;
            }
        }

        // Movement history: rising purchase costs so the methods diverge,
        // only for products that have no history yet (idempotency).
        $service = app(InventoryService::class);
        $start = Carbon::parse($fiscalYear->start_date)->startOfMonth();
        $moved = 0;

        $history = [
            'LAP-DEV-01' => [
                ['day' => 2, 'do' => 'purchase', 'qty' => 5, 'amount' => 180000],
                ['day' => 8, 'do' => 'purchase', 'qty' => 5, 'amount' => 200000],
                ['day' => 12, 'do' => 'sale', 'qty' => 6, 'amount' => 260000],
            ],
            'CBL-HDMI-01' => [
                ['day' => 2, 'do' => 'purchase', 'qty' => 50, 'amount' => 800],
                ['day' => 9, 'do' => 'purchase', 'qty' => 50, 'amount' => 1000],
                ['day' => 13, 'do' => 'sale', 'qty' => 60, 'amount' => 1500],
            ],
            'PPR-A4-01' => [
                ['day' => 3, 'do' => 'purchase', 'qty' => 100, 'amount' => 1200],
                ['day' => 10, 'do' => 'purchase', 'qty' => 100, 'amount' => 1400],
                ['day' => 14, 'do' => 'sale', 'qty' => 120, 'amount' => 1800],
            ],
            'TNR-HP-01' => [
                ['day' => 4, 'do' => 'purchase', 'qty' => 10, 'amount' => 28000],
                ['day' => 15, 'do' => 'sale', 'qty' => 4, 'amount' => 36000],
            ],
        ];

        foreach ($history as $sku => $steps) {
            $product = Product::where('sku', $sku)->first();

            if (! $product || $product->movements()->exists()) {
                continue;
            }

            foreach ($steps as $step) {
                $date = $start->copy()->day($step['day']);

                if ($date->greaterThan(now())) {
                    continue;
                }

                if ($step['do'] === 'purchase') {
                    $service->purchase($product, $step['qty'], $step['amount'], $date->toDateString(), 'SEED');
                } else {
                    $service->sale($product, $step['qty'], $step['amount'], $date->toDateString(), 'SEED');
                }

                $moved++;
            }
        }

        $this->command?->info("Inventory: {$created} products created, {$moved} movements booked.");
    }
}
