<?php

namespace App\Modules\Attendance\Filament\Resources\OvertimeRequests\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Attendance\Filament\Resources\OvertimeRequests\OvertimeRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOvertimeRequests extends ListRecords
{
    protected static string $resource = OvertimeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('overtime-requests', 'Overtime Requests: Help'),
            CreateAction::make(),
        ];
    }
}
