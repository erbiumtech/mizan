<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use Filament\Resources\Pages\EditRecord;

class EditGoodsReceipt extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = GoodsReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-goods-receipts', 'Deliveries: Help')];
    }
}
