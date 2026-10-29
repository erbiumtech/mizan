<?php

namespace App\Modules\Accounting\Filament\Resources\ChequeBooks\Pages;

use App\Modules\Accounting\Filament\Resources\ChequeBooks\ChequeBookResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditChequeBook extends EditRecord
{
    protected static string $resource = ChequeBookResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
