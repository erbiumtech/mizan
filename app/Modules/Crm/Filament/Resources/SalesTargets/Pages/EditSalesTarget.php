<?php

namespace App\Modules\Crm\Filament\Resources\SalesTargets\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\SalesTargets\SalesTargetResource;
use Filament\Resources\Pages\EditRecord;

class EditSalesTarget extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = SalesTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
