<?php

namespace App\Modules\Leave\Filament\Resources\LeaveRequests;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Leave\Filament\Resources\LeaveRequests\Pages\CreateLeaveRequest;
use App\Modules\Leave\Filament\Resources\LeaveRequests\Pages\EditLeaveRequest;
use App\Modules\Leave\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Modules\Leave\Filament\Resources\LeaveRequests\Schemas\LeaveRequestForm;
use App\Modules\Leave\Filament\Resources\LeaveRequests\Tables\LeaveRequestsTable;
use App\Modules\Leave\Models\LeaveRequest;
use App\Support\NavigationBadge;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class LeaveRequestResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = LeaveRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $recordTitleAttribute = 'reason';

    protected static ?string $modelLabel = 'Leave request';

    protected static ?int $navigationSort = 10;

    /**
     * Own requests, and a manager's downline.
     *
     * The same scoping payslips and expense claims use. Leave is no more public than
     * a salary — a sick-leave record says something about somebody's health, and the
     * reason field is free text they wrote expecting their manager to read it, not
     * the company.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    /** Pending requests, so an approver sees there is something waiting. */
    public static function getNavigationBadge(): ?string
    {
        return NavigationBadge::of(static::class, fn (): int => static::getEloquentQuery()->pending()->count());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return LeaveRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveRequests::route('/'),
            'create' => CreateLeaveRequest::route('/create'),
            'edit' => EditLeaveRequest::route('/{record}/edit'),
        ];
    }
}
