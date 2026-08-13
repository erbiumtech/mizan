<?php

namespace App\Modules\Attendance\Filament\Resources\AttendanceDays\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Attendance\Filament\Resources\AttendanceDays\AttendanceDayResource;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceRecorder;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAttendanceDay extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = AttendanceDayResource::class;

    /** Through the recorder, for the same reasons the create page gives. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(AttendanceRecorder::class)->record(
            employee: $record->employee,
            date: $record->date,
            status: $data['status'],
            checkIn: isset($data['check_in_at']) ? substr((string) $data['check_in_at'], 0, 5) : null,
            checkOut: isset($data['check_out_at']) ? substr((string) $data['check_out_at'], 0, 5) : null,
            source: AttendanceDay::SOURCE_MANUAL,
            note: $data['note'] ?? null,
        );
    }

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
