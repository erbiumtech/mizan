<?php

namespace App\Modules\Lifecycle\Filament\Resources\IssuedAssets\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Lifecycle\Filament\Resources\IssuedAssets\IssuedAssetResource;
use Filament\Resources\Pages\EditRecord;

class EditIssuedAsset extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = IssuedAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
