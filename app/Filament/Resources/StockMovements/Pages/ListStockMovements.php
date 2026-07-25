<?php

namespace App\Filament\Resources\StockMovements\Pages;

use App\Filament\Resources\StockMovements\StockMovementResource;
use Filament\Resources\Pages\ListRecords;

class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    // Read-only resource — no create header action (parity with Nova).
    protected function getHeaderActions(): array
    {
        return [];
    }
}
