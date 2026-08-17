<?php

namespace App\Modules\Construction\Filament\Resources\CostCodes\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Construction\Filament\Resources\CostCodes\CostCodeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCostCode extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = CostCodeResource::class;
}
