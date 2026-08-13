<?php

namespace App\Modules\Attendance\Filament\Resources\AttendanceDays;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Attendance\Filament\Resources\AttendanceDays\Pages\CreateAttendanceDay;
use App\Modules\Attendance\Filament\Resources\AttendanceDays\Pages\EditAttendanceDay;
use App\Modules\Attendance\Filament\Resources\AttendanceDays\Pages\ListAttendanceDays;
use App\Modules\Attendance\Filament\Resources\AttendanceDays\Schemas\AttendanceDayForm;
use App\Modules\Attendance\Filament\Resources\AttendanceDays\Tables\AttendanceDaysTable;
use App\Modules\Attendance\Models\AttendanceDay;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AttendanceDayResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = AttendanceDay::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $modelLabel = 'Attendance';

    protected static ?string $pluralModelLabel = 'Attendance';

    protected static ?int $navigationSort = 40;

    /** Own record and downline, the same scoping payslips and leave use. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    /**
     * Days nobody has answered for, this month.
     *
     * The one number worth putting in the sidebar: `not_marked` accumulating is what
     * makes the whole distinction from `absent` stop meaning anything, and a badge is
     * how somebody notices before a payroll clerk does.
     */
    public static function getNavigationBadge(): ?string
    {
        $unknown = static::getEloquentQuery()
            ->unknown()
            ->whereBetween('date', [
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ])
            ->count();

        return $unknown > 0 ? (string) $unknown : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return AttendanceDayForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttendanceDaysTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendanceDays::route('/'),
            'create' => CreateAttendanceDay::route('/create'),
            'edit' => EditAttendanceDay::route('/{record}/edit'),
        ];
    }
}
