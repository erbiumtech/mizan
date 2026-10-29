<?php

namespace App\Modules\Accounting\Filament\Resources\Cheques\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\Cheques\ChequeResource;
use Filament\Resources\Pages\ListRecords;

class ListCheques extends ListRecords
{
    protected static string $resource = ChequeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('cheques', 'Cheques: Help'),
        ];
    }
}
