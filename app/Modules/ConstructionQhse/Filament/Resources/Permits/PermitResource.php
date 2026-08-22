<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Permits;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionQhse\Filament\Resources\Permits\Pages\CreatePermit;
use App\Modules\ConstructionQhse\Filament\Resources\Permits\Pages\EditPermit;
use App\Modules\ConstructionQhse\Filament\Resources\Permits\Pages\ListPermits;
use App\Modules\ConstructionQhse\Filament\Resources\Permits\Schemas\PermitForm;
use App\Modules\ConstructionQhse\Filament\Resources\Permits\Tables\PermitsTable;
use App\Modules\ConstructionQhse\Models\Permit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Permits to work — `docs/construction-management-plan.md` §17.5.
 *
 * **The badge counts permits past their window and still open**, and §17.5 names it as the reason this register exists:
 * "a permit is time-boxed, and an expired-but-open permit is the failure mode that kills people." It is the third badge
 * in this module and it clears the *silent* test with room to spare — nothing else in the application knows the window
 * closed, and nobody walks back to a permit they think expired itself.
 *
 * **Nothing auto-closes them.** That would be the obvious convenience and it is exactly wrong: a permit quietly marked
 * closed by a scheduled job is a hazard nobody went back to. Expiry makes it visible; a person closes it.
 */
class PermitResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Permit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Quality & Safety';

    protected static ?string $recordTitleAttribute = 'permit_number';

    protected static ?int $navigationSort = 30;

    protected static ?string $label = 'Permit to work';

    protected static ?string $pluralLabel = 'Permits';

    /** Expired and still open — one indexed count against `(status, valid_to)`. */
    public static function getNavigationBadge(): ?string
    {
        $expired = Permit::query()->expiredAndOpen()->count();

        return $expired > 0 ? (string) $expired : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        // `extends` too: an extension row prints the number of the permit it extends, and a register of extensions
        // reading that lazily is one query per row.
        return parent::getEloquentQuery()->with(['job', 'location', 'extends']);
    }

    public static function form(Schema $schema): Schema
    {
        return PermitForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PermitsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPermits::route('/'),
            'create' => CreatePermit::route('/create'),
            'edit' => EditPermit::route('/{record}/edit'),
        ];
    }
}
