<?php

namespace App\Modules\Crm\Filament\Resources\Leads;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Filament\Support\RecordSheet;
use App\Modules\Crm\Filament\Resources\Leads\Pages\CreateLead;
use App\Modules\Crm\Filament\Resources\Leads\Pages\EditLead;
use App\Modules\Crm\Filament\Resources\Leads\Pages\ListLeads;
use App\Modules\Crm\Filament\Resources\Leads\Schemas\LeadForm;
use App\Modules\Crm\Filament\Resources\Leads\Schemas\LeadInfolist;
use App\Modules\Crm\Filament\Resources\Leads\Tables\LeadsTable;
use App\Modules\Crm\Models\Lead;
use App\Support\NavigationBadge;
use App\Support\ResourceContributions;
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

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::SheetView;

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
    /** The query behind the badge. Batched with every other one on the page — see NavigationBadge. */
    public static function badgeQuery(): ?Builder
    {
        return static::getEloquentQuery()->open();
    }

    public static function getNavigationBadge(): ?string
    {
        return NavigationBadge::of(static::class);
    }

    public static function form(Schema $schema): Schema
    {
        return LeadForm::configure($schema);
    }

    /**
     * Read in the list's view sheet: the record's values rather than its edit form disabled —
     * docs/side-sheets.md.
     */
    public static function infolist(Schema $schema): Schema
    {
        return LeadInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeadsTable::configure($table);
    }

    /** What other modules show on a lead — Campaigns' consent, offered from its own provider. */
    public static function getRelations(): array
    {
        return ResourceContributions::relationManagersFor(static::class);
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
