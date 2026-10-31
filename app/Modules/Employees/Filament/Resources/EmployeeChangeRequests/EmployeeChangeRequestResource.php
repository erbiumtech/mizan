<?php

namespace App\Modules\Employees\Filament\Resources\EmployeeChangeRequests;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\RecordSheet;
use App\Modules\Employees\Filament\Resources\EmployeeChangeRequests\Pages\ListEmployeeChangeRequests;
use App\Modules\Employees\Filament\Resources\EmployeeChangeRequests\Schemas\EmployeeChangeRequestForm;
use App\Modules\Employees\Filament\Resources\EmployeeChangeRequests\Tables\EmployeeChangeRequestsTable;
use App\Modules\Employees\Models\EmployeeChangeRequest;
use App\Support\NavigationBadge;
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

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Page;

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
    /**
     * The query behind the badge, or nothing for somebody who may not approve.
     *
     * Returning null rather than an empty query is what keeps this resource out of the batched
     * statement entirely: a reader who cannot approve should not be costing a subquery to be told
     * a number they are not shown.
     */
    public static function badgeQuery(): ?Builder
    {
        if (! (auth()->user()?->can('EmployeeChangeApprove') ?? false)) {
            return null;
        }

        return EmployeeChangeRequest::query()->where('status', EmployeeChangeRequest::STATUS_PENDING);
    }

    public static function getNavigationBadge(): ?string
    {
        return NavigationBadge::of(static::class);
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
