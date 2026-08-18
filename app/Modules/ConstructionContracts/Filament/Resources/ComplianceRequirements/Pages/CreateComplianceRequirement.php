<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ComplianceRequirements\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceRequirements\ComplianceRequirementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateComplianceRequirement extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ComplianceRequirementResource::class;
}
