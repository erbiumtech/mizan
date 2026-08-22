<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Inspections;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionQhse\Filament\Resources\Inspections\Pages\CreateInspection;
use App\Modules\ConstructionQhse\Filament\Resources\Inspections\Pages\EditInspection;
use App\Modules\ConstructionQhse\Filament\Resources\Inspections\Pages\ListInspections;
use App\Modules\ConstructionQhse\Filament\Resources\Inspections\RelationManagers\ChecksRelationManager;
use App\Modules\ConstructionQhse\Filament\Resources\Inspections\Schemas\InspectionForm;
use App\Modules\ConstructionQhse\Filament\Resources\Inspections\Tables\InspectionsTable;
use App\Modules\ConstructionQhse\Models\Inspection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Inspections and hold points — `docs/construction-management-plan.md` §17.1.
 *
 * **The badge counts hold points that have passed and not been released**, and this is the one place in this module where
 * a badge earns its per-page query by the rule Phase 9g settled: work is standing still, and every day it stands still
 * costs money nobody is recording. It is a single indexed count against
 * `(point_type, status, released_hold_point)`.
 *
 * The register's other job is the witness-point evidence: a party invited who did not attend is why work lawfully
 * proceeded, and nobody writes that down at the time.
 */
class InspectionResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Inspection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Quality & Safety';

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?int $navigationSort = 10;

    protected static ?string $label = 'Inspection';

    protected static ?string $pluralLabel = 'Inspections';

    /**
     * Hold points waiting on a release — work standing still.
     *
     * One indexed count, per Phase 9g's rule, and it earns its place because the failure is silent: the inspection
     * passed, everybody moved on, and the next operation is waiting for a signature nobody knows is missing.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Inspection::query()->awaitingRelease()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['job', 'location', 'itpActivity']);
    }

    public static function form(Schema $schema): Schema
    {
        return InspectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InspectionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ChecksRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInspections::route('/'),
            'create' => CreateInspection::route('/create'),
            'edit' => EditInspection::route('/{record}/edit'),
        ];
    }
}
