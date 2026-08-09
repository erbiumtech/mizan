<?php

namespace App\Modules\Payroll\Filament\Resources\PayComponents\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Payroll\Filament\Resources\PayComponents\PayComponentResource;
use Filament\Resources\Pages\ListRecords;

class ListPayComponents extends ListRecords
{
    protected static string $resource = PayComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('pay-components', 'Pay Components: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
