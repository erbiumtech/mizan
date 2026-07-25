<?php

namespace App\Filament\Resources\MPRs\Pages;

use App\Filament\Resources\MPRs\MPRResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMPRS extends ListRecords
{
    protected static string $resource = MPRResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
