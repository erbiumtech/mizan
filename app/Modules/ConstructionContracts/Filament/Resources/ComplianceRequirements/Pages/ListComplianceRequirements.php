<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ComplianceRequirements\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceRequirements\ComplianceRequirementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListComplianceRequirements extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = ComplianceRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-compliance', 'Compliance: Help'),
            CreateAction::make(),
        ];
    }
}
