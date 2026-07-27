<?php

namespace App\Filament\Resources\FixedAssets\Pages;

use App\Filament\Concerns\InteractsWithCustomFields;
use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\FixedAssets\FixedAssetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFixedAsset extends EditRecord
{
    use InteractsWithCustomFields, RedirectsToIndex;

    protected static string $resource = FixedAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
