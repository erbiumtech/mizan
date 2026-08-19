<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ComplianceDocuments\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceDocuments\ComplianceDocumentResource;
use Filament\Resources\Pages\EditRecord;

class EditComplianceDocument extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ComplianceDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-compliance', 'Compliance: Help')];
    }
}
