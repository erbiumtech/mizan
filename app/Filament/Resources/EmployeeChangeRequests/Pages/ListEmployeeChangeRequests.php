<?php

namespace App\Filament\Resources\EmployeeChangeRequests\Pages;

use App\Filament\Resources\EmployeeChangeRequests\EmployeeChangeRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeChangeRequests extends ListRecords
{
    protected static string $resource = EmployeeChangeRequestResource::class;

    protected function getHeaderActions(): array
    {
        // Requests are created by editing your own Employee record — no create action.
        return [];
    }
}
