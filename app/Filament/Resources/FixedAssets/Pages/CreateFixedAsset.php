<?php

namespace App\Filament\Resources\FixedAssets\Pages;

use App\Filament\Concerns\InteractsWithCustomFields;
use App\Filament\Resources\FixedAssets\FixedAssetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFixedAsset extends CreateRecord
{
    use InteractsWithCustomFields;

    protected static string $resource = FixedAssetResource::class;
}
