<?php

namespace App\Modules\Leave\Filament\Resources\LeaveTypes\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Leave\Filament\Resources\LeaveTypes\LeaveTypeResource;
use Filament\Resources\Pages\ListRecords;

class ListLeaveTypes extends ListRecords
{
    protected static string $resource = LeaveTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('leave-types', 'Leave Types: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
