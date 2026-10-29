<?php

namespace App\Modules\Leave\Filament\Resources\LeaveEncashments\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Leave\Filament\Resources\LeaveEncashments\LeaveEncashmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLeaveEncashments extends ListRecords
{
    protected static string $resource = LeaveEncashmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('leave-encashments', 'Leave Encashments: Help'),
            CreateAction::make(),
        ];
    }
}
