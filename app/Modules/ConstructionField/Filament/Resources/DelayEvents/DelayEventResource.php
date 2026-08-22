<?php

namespace App\Modules\ConstructionField\Filament\Resources\DelayEvents;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionField\Filament\Resources\DelayEvents\Pages\CreateDelayEvent;
use App\Modules\ConstructionField\Filament\Resources\DelayEvents\Pages\EditDelayEvent;
use App\Modules\ConstructionField\Filament\Resources\DelayEvents\Pages\ListDelayEvents;
use App\Modules\ConstructionField\Filament\Resources\DelayEvents\Schemas\DelayEventForm;
use App\Modules\ConstructionField\Filament\Resources\DelayEvents\Tables\DelayEventsTable;
use App\Modules\ConstructionField\Models\DelayEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Delay events and their notice clock — `docs/construction-management-plan.md` §13.
 *
 * **The register exists to make one date impossible to miss.** §13 calls the notice clock "the most valuable thing in
 * this section", and the reason is that its failure is silent: the event happened, nobody wrote inside the window, and
 * the entitlement is gone with no wrong number anywhere for a report to find.
 *
 * So the table leads with days remaining, badges the time-barred ones in red, and offers *Awaiting notice* and
 * *Time-barred* as filters. The nightly `construction:check-delay-notices` command is the other half — a screen nobody
 * opens is not a control.
 */
class DelayEventResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = DelayEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?int $navigationSort = 10;

    protected static ?string $label = 'Delay event';

    protected static ?string $pluralLabel = 'Delay events';

    /** The count of events still needing a notice, because it is the number somebody should act on. */
    public static function getNavigationBadge(): ?string
    {
        $awaiting = DelayEvent::query()->awaitingNotice()->count();

        return $awaiting > 0 ? (string) $awaiting : null;
    }

    /**
     * **Amber, always, and that is a considered answer rather than a missing feature.**
     *
     * The obvious version turns red once an awaiting-notice event is already time-barred — and it cost a second query on
     * every page in the panel to decide a shade. Phase 9g measured it: `construction_field` ships five registers, each
     * wanted a badge, and `PanelPerformanceTest` priced the lot.
     *
     * The count is the actionable number and it is already here. Which of those events is time-barred is on the
     * register, where the rows are loaded anyway and the badge is red per row. A colour is not worth a query the whole
     * application pays for.
     */
    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['job', 'concurrentWith']);
    }

    public static function form(Schema $schema): Schema
    {
        return DelayEventForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DelayEventsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDelayEvents::route('/'),
            'create' => CreateDelayEvent::route('/create'),
            'edit' => EditDelayEvent::route('/{record}/edit'),
        ];
    }
}
