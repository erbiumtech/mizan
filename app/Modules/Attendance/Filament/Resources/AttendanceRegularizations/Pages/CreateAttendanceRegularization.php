<?php

namespace App\Modules\Attendance\Filament\Resources\AttendanceRegularizations\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Attendance\Filament\Resources\AttendanceRegularizations\AttendanceRegularizationResource;
use App\Modules\Attendance\Models\AttendanceRegularization;
use App\Modules\Attendance\Services\RegularizationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAttendanceRegularization extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = AttendanceRegularizationResource::class;

    /**
     * Through the service, which refuses what cannot be corrected before anything is
     * written: a day covered by approved leave, and a day inside a payroll month that
     * has been signed off.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(RegularizationService::class)->submit(
            new AttendanceRegularization($data),
            auth()->user(),
        );
    }
}
