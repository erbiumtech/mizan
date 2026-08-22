<?php

namespace App\Modules\Leave\Filament\Resources\LeaveTypes\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Leave\Filament\Resources\LeaveTypes\LeaveTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLeaveType extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = LeaveTypeResource::class;
}
