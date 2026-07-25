<?php

namespace App\Filament\Resources\SalarySlabs\Pages;

use App\Filament\Resources\SalarySlabs\SalarySlabResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSalarySlab extends EditRecord
{
    protected static string $resource = SalarySlabResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
