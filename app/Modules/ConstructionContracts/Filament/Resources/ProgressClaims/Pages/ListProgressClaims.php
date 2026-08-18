<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\ProgressClaimResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProgressClaims extends ListRecords
{
    protected static string $resource = ProgressClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-certificates', 'Claims and certificates: Help'),
            CreateAction::make(),
        ];
    }
}
