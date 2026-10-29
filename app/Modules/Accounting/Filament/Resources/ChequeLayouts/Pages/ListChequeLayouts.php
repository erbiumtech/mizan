<?php

namespace App\Modules\Accounting\Filament\Resources\ChequeLayouts\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\ChequeLayouts\ChequeLayoutResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChequeLayouts extends ListRecords
{
    protected static string $resource = ChequeLayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('cheque-layouts', 'Cheque Layouts: Help'),
            CreateAction::make(),
        ];
    }
}
