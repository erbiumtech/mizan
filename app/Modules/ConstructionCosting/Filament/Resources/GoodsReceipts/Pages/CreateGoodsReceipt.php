<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\Pages;

use App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Services\GoodsReceiptService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGoodsReceipt extends CreateRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    /**
     * Opened through `GoodsReceiptService`, which numbers it and records who signed for the delivery.
     *
     * Who received it is the first question when the material turns out to be wrong, and the person typing the receipt
     * is the person who was standing there.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $commitment = ($data['commitment_id'] ?? null)
            ? Commitment::query()->find($data['commitment_id'])
            : null;

        unset($data['commitment_id']);

        return app(GoodsReceiptService::class)->create($commitment, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    /** Straight to the lines: what arrived is the document. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
