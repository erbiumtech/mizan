<?php

namespace App\Modules\Core\Filament\Resources\FiscalYears\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\FiscalYears\FiscalYearResource;
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
