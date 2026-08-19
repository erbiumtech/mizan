<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Trades\Pages;

use App\Modules\ConstructionCosting\Filament\Resources\Trades\TradeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTrade extends CreateRecord
{
    protected static string $resource = TradeResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
