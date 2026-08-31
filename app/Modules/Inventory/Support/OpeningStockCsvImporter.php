<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockLocation;
use App\Support\Contracts\CsvImporter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What was on the shelf on the day a company arrived — `docs/erpnext-gap-plan.md` Phase 5.
 *
 * ERPNext calls this a Stock Reconciliation. Here, `stock_movements` could always express it and there was no
 * screen, so a company arriving with stock had a valuation the ledger knew — 1300 came in with the opening
 * trial balance — and a shelf the application thought was empty. Every sale then took cost from nothing.
 *
 * **One lot per row, and no posting.** The value is already in 1300 from the trial balance; posting these as
 * purchases would double it. So each row creates the movement that a receipt creates — a lot with a known
 * cost and a `remaining_quantity` — and leaves `journal_entry_id` null.
 *
 * **Typed `purchase`, which is the lot-creating type**, rather than a new enum value. The valuation engine
 * consumes any movement with quantity remaining, and adding an `opening` type to that enum would mean a
 * migration on every tenant plus every `match` on movement type in the application. `reference` says
 * "Opening stock" instead, which is what somebody reading the product's history needs to see.
 *
 * **Re-runnable, matched on the product and the reference**, as the contract asks. Correcting a quantity
 * re-uploads the file; the earlier opening lot for that product is replaced rather than added to, because two
 * opening lots for one product is the mistake this would otherwise make quietly.
 */
class OpeningStockCsvImporter implements CsvImporter
{
    /** What marks a movement as this import's, so re-running replaces rather than duplicates. */
    public const REFERENCE = 'Opening stock';

    public function key(): string
    {
        return 'opening_stock';
    }

    public function label(): string
    {
        return 'Opening stock';
    }

    public function columns(): array
    {
        return ['sku', 'quantity', 'unit_cost', 'location'];
    }

    public function example(): array
    {
        return ['WIDGET-01', '120', '450.00', ''];
    }

    public function dateField(): ?array
    {
        return [
            'label' => 'Stock on hand as at',
            'help' => 'The date the count was taken — usually the day before your first month here, the same '
                .'date as your opening balances.',
        ];
    }

    public function problemWith(array $row): ?string
    {
        if (trim((string) ($row['sku'] ?? '')) === '') {
            return 'no SKU';
        }

        if (! $this->productFor($row)) {
            return "no product with SKU {$row['sku']} — import or create your products first";
        }

        if ($this->amount($row['quantity'] ?? '') <= 0) {
            return 'a quantity of nothing — leave the row out instead';
        }

        if ($this->amount($row['unit_cost'] ?? '') < 0) {
            return 'a negative cost';
        }

        // Zero is allowed and a blank is not, and the difference is real: free stock is a thing (a sample, a
        // supplier replacement) and a missing cost is a column somebody forgot to fill, which would value the
        // whole shelf at nothing and take every future sale's cost from it.
        if (trim((string) ($row['unit_cost'] ?? '')) === '') {
            return 'no unit cost — stock with no cost values the shelf at nothing';
        }

        if (filled($row['location'] ?? '') && ! $this->locationFor($row)) {
            return "no stock location called {$row['location']}";
        }

        return null;
    }

    public function write(Collection $rows, ?string $date = null): int
    {
        $date = Carbon::parse($date ?? now())->toDateString();
        $written = 0;

        foreach ($rows as $row) {
            $product = $this->productFor($row);
            $location = $this->locationFor($row);
            $quantity = round($this->amount($row['quantity']), 2);
            $unitCost = round($this->amount($row['unit_cost']), 2);

            /*
             * The previous opening lot for this product and place, removed first.
             *
             * `delete()` rather than an update, because a lot that has already been partly sold has a
             * `remaining_quantity` below its quantity and there is no honest way to re-open it: re-running
             * this file is a correction of the *opening position*, which is a thing that happens during
             * setup, before anything has been sold. Scoped to this importer's own reference and to unposted
             * rows, so it can never touch a movement that a real receipt or sale created.
             */
            $product->movements()
                ->where('reference', self::REFERENCE)
                ->whereNull('journal_entry_id')
                ->where('stock_location_id', $location?->getKey())
                ->delete();

            $product->movements()->create([
                'type' => 'purchase',
                'stock_location_id' => $location?->getKey(),
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'remaining_quantity' => $quantity,
                'movement_date' => $date,
                'reference' => self::REFERENCE,
                // Null, and deliberately: the trial balance already put this value in 1300. See the class
                // docblock — a posting here would double the company's inventory.
                'journal_entry_id' => null,
            ]);

            $written++;
        }

        return $written;
    }

    private function productFor(array $row): ?Product
    {
        $sku = trim((string) ($row['sku'] ?? ''));

        return $sku === '' ? null : Product::where('sku', $sku)->first();
    }

    private function locationFor(array $row): ?StockLocation
    {
        $name = trim((string) ($row['location'] ?? ''));

        return $name === '' ? null : StockLocation::where('name', $name)->first();
    }

    /** Spreadsheets export thousands separators, and refusing a row over a comma is a poor trade. */
    private function amount(string $value): float
    {
        return (float) str_replace(',', '', $value ?: '0');
    }
}
