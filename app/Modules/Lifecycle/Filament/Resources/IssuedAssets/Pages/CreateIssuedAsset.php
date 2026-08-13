<?php

namespace App\Modules\Lifecycle\Filament\Resources\IssuedAssets\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Lifecycle\Filament\Resources\IssuedAssets\IssuedAssetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIssuedAsset extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = IssuedAssetResource::class;
}
