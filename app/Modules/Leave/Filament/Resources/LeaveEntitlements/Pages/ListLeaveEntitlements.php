<?php

namespace App\Modules\Leave\Filament\Resources\LeaveEntitlements\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Leave\Filament\Resources\LeaveEntitlements\LeaveEntitlementResource;
use Filament\Resources\Pages\ListRecords;

class ListLeaveEntitlements extends ListRecords
{
    protected static string $resource = LeaveEntitlementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('leave-balances', 'Leave Balances: Help'),
            // Creating one by hand is for a type added mid-year, or opening data at
            // set-up. The nightly command is what normally writes these.
            \Filament\Actions\CreateAction::make()->label('Add an entitlement'),
        ];
    }
}
