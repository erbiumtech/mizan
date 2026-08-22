<?php

namespace App\Modules\Crm\Filament\Resources\SalesTargets\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\SalesTargets\SalesTargetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesTarget extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = SalesTargetResource::class;
}
