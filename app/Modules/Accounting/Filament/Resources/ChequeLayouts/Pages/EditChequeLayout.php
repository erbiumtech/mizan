<?php

namespace App\Modules\Accounting\Filament\Resources\ChequeLayouts\Pages;

use App\Modules\Accounting\Filament\Resources\ChequeLayouts\ChequeLayoutResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditChequeLayout extends EditRecord
{
    protected static string $resource = ChequeLayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ChequeLayoutResource::testPrintAction()->record($this->getRecord()),
            DeleteAction::make(),
        ];
    }
}
