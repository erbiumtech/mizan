<?php

namespace App\Modules\Inventory\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * What is on the shelf, what it is worth, and whether the accounts agree.
 *
 * `docs/reports-expansion-plan.md` Phase 2.4. The valuation engine was used by `InventoryService` and
 * `InvoiceService` when *posting* and by nothing that answered the question a stocktake asks.
 *
 * **It reconciles.** Each product names an inventory account, so the stock value of the products pointing at
 * an account is the figure that account should hold — which makes this the second Phase 2 report after the
 * payroll register with a real tie rather than a statement that there is none.
 *
 * **The flags are columns, not separate reports**, which is the plan's instruction: a product both below its
 * reorder level *and* untouched for months is the interesting case, and two reports would put those facts on
 * two screens.
 */
class StockOnHand extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $title = 'Stock on Hand';

    protected static ?int $navigationSort = 45;

    protected function getHeaderActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('stock-on-hand', 'Stock on Hand: Help'),
        ];
    }
}
