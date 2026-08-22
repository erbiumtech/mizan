<?php

namespace App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments\EmployeeDocumentResource;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeDocument extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = EmployeeDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
