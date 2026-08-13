<?php

namespace App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments\EmployeeDocumentResource;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeDocuments extends ListRecords
{
    protected static string $resource = EmployeeDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('employee-documents', 'Employee Documents: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
