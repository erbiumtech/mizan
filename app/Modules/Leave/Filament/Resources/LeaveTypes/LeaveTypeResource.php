<?php

namespace App\Modules\Leave\Filament\Resources\LeaveTypes;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Leave\Filament\Resources\LeaveTypes\Pages\CreateLeaveType;
use App\Modules\Leave\Filament\Resources\LeaveTypes\Pages\EditLeaveType;
use App\Modules\Leave\Filament\Resources\LeaveTypes\Pages\ListLeaveTypes;
use App\Modules\Leave\Filament\Resources\LeaveTypes\Schemas\LeaveTypeForm;
use App\Modules\Leave\Filament\Resources\LeaveTypes\Tables\LeaveTypesTable;
use App\Modules\Leave\Models\LeaveType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Reference data, not settings. A company that wants 18 annual days edits a row here
 * — no deploy, no toggle, and no combination for us to support. docs/hrms-plan.md §4.7.
 */
class LeaveTypeResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = LeaveType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?string $modelLabel = 'Leave type';

    // Below Leave Requests: a company reads its types far less often than it files
    // leave against them.
    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return LeaveTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveTypes::route('/'),
            'create' => CreateLeaveType::route('/create'),
            'edit' => EditLeaveType::route('/{record}/edit'),
        ];
    }
}
