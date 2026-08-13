<?php

namespace App\Modules\Attendance\Filament\Resources\AttendanceDays\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Attendance\Filament\Resources\AttendanceDays\AttendanceDayResource;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceRecorder;
use App\Modules\Employees\Models\Employee;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAttendanceDay extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = AttendanceDayResource::class;

    /**
     * Written through the recorder, never straight from the form.
     *
     * The recorder is what refuses a day that contradicts approved leave and what
     * derives worked minutes, overtime and lateness from the clock times. Saving the
     * model directly would skip all four — and the importer and the regularization
     * approval go through the same method, so there is one answer rather than three.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(AttendanceRecorder::class)->record(
            employee: Employee::findOrFail($data['employee_id']),
            date: $data['date'],
            status: $data['status'],
            checkIn: isset($data['check_in_at']) ? substr((string) $data['check_in_at'], 0, 5) : null,
            checkOut: isset($data['check_out_at']) ? substr((string) $data['check_out_at'], 0, 5) : null,
            source: AttendanceDay::SOURCE_MANUAL,
            note: $data['note'] ?? null,
        );
    }
}
