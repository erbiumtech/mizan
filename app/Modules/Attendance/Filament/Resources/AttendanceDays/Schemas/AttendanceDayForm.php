<?php

namespace App\Modules\Attendance\Filament\Resources\AttendanceDays\Schemas;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Support\EmployeeAccess;
use App\Support\EmployeeOptions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Schema;

class AttendanceDayForm
{
    /** The statuses a person may set. `not_marked` is absent: it is the *default*, not a choice. */
    public static function statusOptions(): array
    {
        return [
            AttendanceDay::STATUS_PRESENT => 'Present',
            AttendanceDay::STATUS_WORK_FROM_HOME => 'Worked from home',
            AttendanceDay::STATUS_HALF_DAY => 'Half day',
            AttendanceDay::STATUS_ABSENT => 'Absent',
            AttendanceDay::STATUS_ON_LEAVE => 'On leave',
            AttendanceDay::STATUS_HOLIDAY => 'Holiday',
            AttendanceDay::STATUS_WEEKLY_OFF => 'Weekly off',
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label('Employee')
                ->relationship('employee', 'employee_id', fn ($query) => app(EmployeeAccess::class)
                    ->scopeAccessibleEmployees($query->with('user'), auth()->user()))
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search(
                    $search,
                    EmployeeOptions::accessibleScope(),
                ))
                ->preload()
                ->required(),

            DatePicker::make('date')->native(false)->required(),

            Select::make('status')
                ->options(static::statusOptions())
                ->required()
                ->helperText('"Not marked" is not offered: it is what a day says before anybody answers for it, and it must never be set deliberately.'),

            TimePicker::make('check_in_at')->seconds(false)->label('In'),
            TimePicker::make('check_out_at')->seconds(false)->label('Out'),

            TextInput::make('note')->maxLength(255)->columnSpanFull(),
        ]);
    }
}
