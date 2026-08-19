<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\BackCharges;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionContracts\Filament\Resources\BackCharges\Pages\CreateBackCharge;
use App\Modules\ConstructionContracts\Filament\Resources\BackCharges\Pages\EditBackCharge;
use App\Modules\ConstructionContracts\Filament\Resources\BackCharges\Pages\ListBackCharges;
use App\Modules\ConstructionContracts\Filament\Resources\BackCharges\Schemas\BackChargeForm;
use App\Modules\ConstructionContracts\Filament\Resources\BackCharges\Tables\BackChargesTable;
use App\Modules\ConstructionContracts\Models\BackCharge;
use App\Support\NavigationBadge;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Back-charges against subcontracts — `docs/construction-management-plan.md` §12.
 *
 * **The screen exists for the un-notified list.** §12's sentence is that an incurred-but-unnotified back-charge is money
 * the company will not get and does not yet know it has lost, and the only cure for "does not yet know" is a register
 * with the figure on it. The default filter is therefore everything still open, and the total at the foot of the amount
 * column is the exposure.
 *
 * **Nothing here deducts by itself.** Applying a charge to a certificate is an action somebody takes, following §16.5's
 * rule for NCRs: the charge proposes, a human confirms and signs for it.
 */
class BackChargeResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = BackCharge::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Contracts';

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?int $navigationSort = 62;

    protected static ?string $label = 'Back-charge';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['contract.job', 'job', 'appliedCertificate']);
    }

    public static function form(Schema $schema): Schema
    {
        return BackChargeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BackChargesTable::configure($table);
    }

    /**
     * The exposure as a badge: the count of charges nobody has served notice of.
     *
     * Through `NavigationBadge`, which caches it for a minute — Filament evaluates every badge as a value while it
     * builds the navigation, on every page of the panel, whether or not the branch holding it is open.
     */
    public static function getNavigationBadge(): ?string
    {
        return NavigationBadge::of(static::class, fn (): int => static::getEloquentQuery()->unnotified()->count());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Back-charges with no notice served — nothing here may be deducted yet.';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBackCharges::route('/'),
            'create' => CreateBackCharge::route('/create'),
            'edit' => EditBackCharge::route('/{record}/edit'),
        ];
    }
}
