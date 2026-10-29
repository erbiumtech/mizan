<?php

namespace App\Modules\Payroll\Filament\Resources\ProvidentFundEntries\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Payroll\Filament\Resources\ProvidentFundEntries\ProvidentFundEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProvidentFundEntries extends ListRecords
{
    protected static string $resource = ProvidentFundEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('provident-fund', 'Provident Fund: Help'),
            CreateAction::make(),
        ];
    }
}
