<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ComplianceRequirements\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceRequirements\ComplianceRequirementResource;
use Filament\Resources\Pages\EditRecord;

class EditComplianceRequirement extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ComplianceRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-compliance', 'Compliance: Help')];
    }
}
