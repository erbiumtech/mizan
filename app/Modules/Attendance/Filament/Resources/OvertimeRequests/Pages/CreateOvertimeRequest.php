<?php

namespace App\Modules\Attendance\Filament\Resources\OvertimeRequests\Pages;

use App\Modules\Attendance\Filament\Resources\OvertimeRequests\OvertimeRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOvertimeRequest extends CreateRecord
{
    protected static string $resource = OvertimeRequestResource::class;
}
