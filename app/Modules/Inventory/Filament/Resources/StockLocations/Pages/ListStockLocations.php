<?php

namespace App\Modules\Inventory\Filament\Resources\StockLocations\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Inventory\Filament\Resources\StockLocations\StockLocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockLocations extends ListRecords
{
    protected static string $resource = StockLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('stock-locations', 'Stock locations: Help'),
            CreateAction::make(),
        ];
    }
}
