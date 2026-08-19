<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\Pages;

use App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\MaterialIssueResource;
use App\Modules\ConstructionCosting\Services\MaterialIssueService;
use App\Modules\Inventory\Models\StockLocation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMaterialIssue extends CreateRecord
{
    protected static string $resource = MaterialIssueResource::class;

    /** Through the service, which numbers the docket and refuses it outright without Inventory. */
    protected function handleRecordCreation(array $data): Model
    {
        $store = StockLocation::query()->findOrFail($data['stock_location_id']);

        unset($data['stock_location_id']);

        return app(MaterialIssueService::class)->create($store, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    /** Straight to the lines: the docket is the lines. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
