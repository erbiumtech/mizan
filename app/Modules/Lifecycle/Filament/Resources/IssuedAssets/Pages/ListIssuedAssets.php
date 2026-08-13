<?php

namespace App\Modules\Lifecycle\Filament\Resources\IssuedAssets\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Lifecycle\Filament\Resources\IssuedAssets\IssuedAssetResource;
use Filament\Resources\Pages\ListRecords;

class ListIssuedAssets extends ListRecords
{
    protected static string $resource = IssuedAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('issued-assets', 'Issued Assets: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
