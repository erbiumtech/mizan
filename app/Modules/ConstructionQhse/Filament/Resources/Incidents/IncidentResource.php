<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Incidents;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionQhse\Filament\Resources\Incidents\Pages\CreateIncident;
use App\Modules\ConstructionQhse\Filament\Resources\Incidents\Pages\EditIncident;
use App\Modules\ConstructionQhse\Filament\Resources\Incidents\Pages\ListIncidents;
use App\Modules\ConstructionQhse\Filament\Resources\Incidents\RelationManagers\WitnessesRelationManager;
use App\Modules\ConstructionQhse\Filament\Resources\Incidents\Schemas\IncidentForm;
use App\Modules\ConstructionQhse\Filament\Resources\Incidents\Tables\IncidentsTable;
use App\Modules\ConstructionQhse\Models\Incident;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Incidents — `docs/construction-management-plan.md` §17.3, to ISO 45001.
 *
 * **Near miss is the first kind on the list and the easiest thing on the screen to report.** §17.3: "near-misses
 * reported per lost-time injury is the leading indicator that predicts the next one." A register where reporting a near
 * miss is harder than reporting an injury suppresses the number it most needs — so reporting is the widest permission in
 * this module and sits with every employee.
 *
 * **The badge counts reportable incidents nobody has reported to an authority**, and it is the only badge in the module
 * besides the hold-point release — by the same rule, whose operative word is *silent*. A statutory duty with a clock on
 * it and nothing else in the application watching is precisely that.
 */
class IncidentResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Incident::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Quality & Safety';

    protected static ?string $recordTitleAttribute = 'incident_number';

    protected static ?int $navigationSort = 25;

    protected static ?string $label = 'Incident';

    protected static ?string $pluralLabel = 'Incidents';

    /**
     * Reportable and not reported — one indexed count.
     *
     * The clock here is statutory rather than contractual, which makes it the one figure in this register that cannot be
     * recovered by looking later.
     */
    public static function getNavigationBadge(): ?string
    {
        $unreported = Incident::query()
            ->reportable()
            ->whereNull('reported_to_authority_on')
            ->count();

        return $unreported > 0 ? (string) $unreported : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['job', 'location']);
    }

    public static function form(Schema $schema): Schema
    {
        return IncidentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IncidentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [WitnessesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIncidents::route('/'),
            'create' => CreateIncident::route('/create'),
            'edit' => EditIncident::route('/{record}/edit'),
        ];
    }
}
