<?php

namespace App\Modules\Accounting\Filament\Resources\FixedAssets\Pages;

use App\Modules\Accounting\Filament\Resources\FixedAssets\FixedAssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFixedAssets extends ListRecords
{
    protected static string $resource = FixedAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
