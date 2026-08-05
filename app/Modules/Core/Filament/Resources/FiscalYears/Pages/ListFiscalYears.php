<?php

namespace App\Modules\Core\Filament\Resources\FiscalYears\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\FiscalYears\FiscalYearResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFiscalYears extends ListRecords
{
    protected static string $resource = FiscalYearResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('fiscal-years', 'Fiscal Years: Help'),
            CreateAction::make(),
        ];
    }
}
