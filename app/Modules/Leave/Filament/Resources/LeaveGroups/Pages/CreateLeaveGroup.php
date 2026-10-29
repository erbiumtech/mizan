<?php

namespace App\Modules\Leave\Filament\Resources\LeaveGroups\Pages;

use App\Modules\Leave\Filament\Resources\LeaveGroups\LeaveGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLeaveGroup extends CreateRecord
{
    protected static string $resource = LeaveGroupResource::class;
}
