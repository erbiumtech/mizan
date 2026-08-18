<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ComplianceDocuments\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceDocuments\ComplianceDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListComplianceDocuments extends ListRecords
{
    protected static string $resource = ComplianceDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-compliance', 'Compliance: Help'),
            CreateAction::make(),
        ];
    }
}
