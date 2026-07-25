<?php

namespace App\Filament\Resources\SalarySlabs\Pages;

use App\Filament\Resources\SalarySlabs\SalarySlabResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalarySlabs extends ListRecords
{
    protected static string $resource = SalarySlabResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
