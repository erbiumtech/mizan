<?php

namespace App\Modules\Attendance\Filament\Resources\AttendanceRegularizations\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Attendance\Filament\Resources\AttendanceRegularizations\AttendanceRegularizationResource;
use Filament\Resources\Pages\ListRecords;

class ListAttendanceRegularizations extends ListRecords
{
    protected static string $resource = AttendanceRegularizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('attendance-corrections', 'Attendance Corrections: Help'),
            \Filament\Actions\CreateAction::make()->label('Ask for a correction'),
        ];
    }
}
