<?php

namespace App\Modules\Lifecycle\Filament\Resources\EmploymentContracts\Pages;

use App\Modules\Lifecycle\Filament\Resources\EmploymentContracts\EmploymentContractResource;
use Filament\Resources\Pages\EditRecord;

class EditEmploymentContract extends EditRecord
{
    protected static string $resource = EmploymentContractResource::class;
}
