<?php

namespace App\Modules\Inventory\Filament\Resources\StockLocations\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\Inventory\Filament\Resources\StockLocations\StockLocationResource;
use Filament\Resources\Pages\EditRecord;

class EditStockLocation extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = StockLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('stock-locations', 'Stock locations: Help')];
    }
}
