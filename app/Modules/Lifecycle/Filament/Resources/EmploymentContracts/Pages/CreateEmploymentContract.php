<?php

namespace App\Modules\Lifecycle\Filament\Resources\EmploymentContracts\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Lifecycle\Filament\Resources\EmploymentContracts\EmploymentContractResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmploymentContract extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = EmploymentContractResource::class;
}
