<?php

namespace App\Modules\Leave\Filament\Resources\LeaveRequests\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Leave\Filament\Resources\LeaveRequests\LeaveRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListLeaveRequests extends ListRecords
{
    protected static string $resource = LeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('leave-requests', 'Leave: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
