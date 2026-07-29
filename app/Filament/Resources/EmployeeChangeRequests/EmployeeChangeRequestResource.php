<?php

namespace App\Filament\Resources\EmployeeChangeRequests;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\EmployeeChangeRequests\Pages\ListEmployeeChangeRequests;
use App\Filament\Resources\EmployeeChangeRequests\Schemas\EmployeeChangeRequestForm;
use App\Filament\Resources\EmployeeChangeRequests\Tables\EmployeeChangeRequestsTable;
use App\Models\EmployeeChangeRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EmployeeChangeRequestResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = EmployeeChangeRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $recordTitleAttribute = 'id';

    public static function getGloballySearchableAttributes(): array
    {
        return ['id'];
    }

    public static function form(Schema $schema): Schema
    {
        return EmployeeChangeRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeChangeRequestsTable::configure($table);
    }

    /**
     * Approvers see every request; employees only their own (Nova indexQuery parity).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && $user->can('EmployeeChangeApprove')) {
            return $query;
        }

        return $query->where('requested_by', $user?->id);
    }

    /**
     * Sidebar pending-count badge for approvers (Nova menu() parity).
     */
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if (! ($user?->can('EmployeeChangeApprove') ?? false)) {
            return null;
        }

        $count = EmployeeChangeRequest::where('status', EmployeeChangeRequest::STATUS_PENDING)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    // Requests are created by editing your own Employee record (Nova authorizedToCreate false).
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeChangeRequests::route('/'),
        ];
    }
}
