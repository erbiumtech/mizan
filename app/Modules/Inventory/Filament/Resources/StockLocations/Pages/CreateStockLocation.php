<?php

namespace App\Modules\Inventory\Filament\Resources\StockLocations\Pages;

use App\Modules\Inventory\Filament\Resources\StockLocations\StockLocationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStockLocation extends CreateRecord
{
    protected static string $resource = StockLocationResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
