<?php

namespace App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments\EmployeeDocumentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeDocument extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = EmployeeDocumentResource::class;
}
