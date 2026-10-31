<?php

namespace App\Modules\Payroll\Filament\Resources\SalaryStructures\Pages;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Payroll\Filament\Resources\SalaryStructures\SalaryStructureResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSalaryStructure extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = SalaryStructureResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), ReplicateRecordAction::make()];
    }
}
