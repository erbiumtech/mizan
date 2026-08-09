<?php

namespace App\Modules\Employees\Filament\Resources\EmployeeChangeRequests\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Employees\Filament\Resources\EmployeeChangeRequests\EmployeeChangeRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeChangeRequests extends ListRecords
{
    protected static string $resource = EmployeeChangeRequestResource::class;

    protected function getHeaderActions(): array
    {
        // Requests are created by editing your own Employee record — no create action.
        return [
            HelpAction::make('employee-change-requests', 'Employee Change Requests: Help'),
        ];
    }
}
