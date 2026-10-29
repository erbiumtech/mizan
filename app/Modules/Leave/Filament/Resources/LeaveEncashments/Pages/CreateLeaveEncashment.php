<?php

namespace App\Modules\Leave\Filament\Resources\LeaveEncashments\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Leave\Filament\Resources\LeaveEncashments\LeaveEncashmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLeaveEncashment extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = LeaveEncashmentResource::class;
}
