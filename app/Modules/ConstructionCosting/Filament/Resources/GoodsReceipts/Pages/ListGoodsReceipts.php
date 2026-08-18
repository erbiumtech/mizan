<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGoodsReceipts extends ListRecords
{
    protected static string $resource = GoodsReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-goods-receipts', 'Deliveries: Help'),
            CreateAction::make(),
        ];
    }
}
