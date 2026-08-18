<?php

namespace App\Modules\Crm\Filament\Resources\Leads;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Crm\Filament\Resources\Leads\Pages\CreateLead;
use App\Modules\Crm\Filament\Resources\Leads\Pages\EditLead;
use App\Modules\Crm\Filament\Resources\Leads\Pages\ListLeads;
use App\Modules\Crm\Filament\Resources\Leads\Schemas\LeadForm;
use App\Modules\Crm\Filament\Resources\Leads\Tables\LeadsTable;
use App\Modules\Crm\Models\Lead;
use App\Support\NavigationBadge;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class LeadResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = Lead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'company_name';

    protected static ?int $navigationSort = 10;

    /**
     * A salesperson's own leads and their downline's — the same BFS every
     * employee-keyed resource uses, so a sales manager sees their team's pipeline and
     * no further.
     *
     * **Guarded on `employees`.** Without that module there is no reporting tree to
     * scope by and `owner_employee_id` is never filled, so scoping on it would hide
     * every lead from everybody. A company in that position gets a flat list, which is
     * the honest behaviour: it has told us nothing about who reports to whom.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (modules()->enabled('employees') && ! static::userIsPrivileged()) {
            $query->whereIn('owner_employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    /** Open leads: what is actually somebody's to work. */
    public static function getNavigationBadge(): ?string
    {
        return NavigationBadge::of(static::class, fn (): int => static::getEloquentQuery()->open()->count());
    }

    public static function form(Schema $schema): Schema
    {
        return LeadForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeadsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
            'create' => CreateLead::route('/create'),
            'edit' => EditLead::route('/{record}/edit'),
        ];
    }
}
