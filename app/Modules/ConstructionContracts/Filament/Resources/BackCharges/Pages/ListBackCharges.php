<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\BackCharges\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionContracts\Filament\Resources\BackCharges\BackChargeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBackCharges extends ListRecords
{
    protected static string $resource = BackChargeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-back-charges', 'Back-charges: Help'),
            CreateAction::make(),
        ];
    }
}
