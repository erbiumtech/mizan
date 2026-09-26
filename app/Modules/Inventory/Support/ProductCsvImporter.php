<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Core\Models\CustomField;
use App\Modules\Inventory\Models\Product;
use App\Support\Contracts\CsvImporter;
use Illuminate\Support\Collection;

/**
 * Products from a spreadsheet.
 *
 * Lived in `Core\Services\CsvImportService` and moved here because `Product` is Inventory's — see
 * docs/module-packaging-plan.md §9.
 */
class ProductCsvImporter implements CsvImporter
{
    public function key(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return 'Products';
    }

    public function columns(): array
    {
        // Plus this tenant's custom fields, as `cf_<code>` columns (multi_select cells use `a|b`).
        return array_merge(['sku', 'name', 'unit', 'description'], CustomField::csvColumns(Product::class));
    }

    public function example(): array
    {
        return array_merge(
            ['SKU-001', 'Laptop stand', 'pcs', 'Aluminium, adjustable'],
            array_fill(0, count(CustomField::csvColumns(Product::class)), ''),
        );
    }

    public function problemWith(array $row): ?string
    {
        return match (true) {
            $row['sku'] === '' => 'no SKU',
            $row['name'] === '' => 'no name',
            default => null,
        };
    }

    public function write(Collection $rows, ?string $date = null): int
    {
        $imported = 0;

        foreach ($rows as $row) {
            // By SKU, so running the same file twice corrects rather than duplicates.
            $product = Product::updateOrCreate(
                ['sku' => $row['sku']],
                [
                    'name' => $row['name'],
                    'unit' => $row['unit'] ?: 'pcs',
                    'description' => $row['description'] ?: null,
                    'is_active' => true,
                ],
            );

            $product->saveCustomFields(CustomField::csvValues(Product::class, $row));

            $imported++;
        }

        return $imported;
    }

    public function dateField(): ?array
    {
        return null;
    }
}
