<?php

namespace App\Modules\Employees\Filament\Resources\Departments\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Employees\Filament\Resources\Departments\DepartmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDepartments extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = DepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('departments', 'Departments: Help'),
            CreateAction::make(),
        ];
    }
}
