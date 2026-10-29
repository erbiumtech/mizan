<?php

namespace App\Modules\Attendance\Filament\Resources\OvertimeRequests\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Attendance\Filament\Resources\OvertimeRequests\OvertimeRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOvertimeRequest extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = OvertimeRequestResource::class;
}
