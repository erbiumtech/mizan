<?php

namespace App\Filament\Resources\FiscalYears\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\FiscalYears\FiscalYearResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFiscalYear extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = FiscalYearResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
