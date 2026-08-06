<?php

namespace App\Modules\Payroll\Filament\Resources\PayComponents\Pages;

use App\Modules\Payroll\Filament\Resources\PayComponents\PayComponentResource;
use Filament\Resources\Pages\ListRecords;

class ListPayComponents extends ListRecords
{
    protected static string $resource = PayComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
