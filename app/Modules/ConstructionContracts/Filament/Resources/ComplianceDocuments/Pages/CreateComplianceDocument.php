<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ComplianceDocuments\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceDocuments\ComplianceDocumentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateComplianceDocument extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ComplianceDocumentResource::class;
}
