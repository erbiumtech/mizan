<?php

namespace App\Modules\Construction\Filament\Resources\CostCodes\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Construction\Filament\Resources\CostCodes\CostCodeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCostCode extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = CostCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
