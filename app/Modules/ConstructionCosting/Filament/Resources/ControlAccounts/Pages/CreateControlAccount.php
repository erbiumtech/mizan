<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts\Pages;

use App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts\ControlAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateControlAccount extends CreateRecord
{
    protected static string $resource = ControlAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
