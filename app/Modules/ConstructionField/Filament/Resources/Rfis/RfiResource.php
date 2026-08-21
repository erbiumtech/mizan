<?php

namespace App\Modules\ConstructionField\Filament\Resources\Rfis;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionField\Filament\Resources\Rfis\Pages\CreateRfi;
use App\Modules\ConstructionField\Filament\Resources\Rfis\Pages\EditRfi;
use App\Modules\ConstructionField\Filament\Resources\Rfis\Pages\ListRfis;
use App\Modules\ConstructionField\Filament\Resources\Rfis\Schemas\RfiForm;
use App\Modules\ConstructionField\Filament\Resources\Rfis\Tables\RfisTable;
use App\Modules\ConstructionField\Models\Rfi;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The RFI register — `docs/construction-management-plan.md` §16.2.
 *
 * **Two reports are what this register is for**, and the screen is built around them rather than around the form.
 * *Whose court is it in* — "seventeen RFIs sitting with the Architect", which §16.2 names — and *what is overdue*, which
 * is the difference between chasing an answer and discovering afterwards that nobody did.
 *
 * The navigation badge counts what is overdue rather than what is open, because open is the normal state of a register
 * and a badge that is always lit is a badge nobody reads.
 */
class RfiResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Rfi::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $recordTitleAttribute = 'rfi_number';

    protected static ?int $navigationSort = 15;

    protected static ?string $label = 'RFI';

    protected static ?string $pluralLabel = 'RFIs';

    /** Overdue, not open: a badge lit by the normal state of a register is one nobody reads. */
    public static function getNavigationBadge(): ?string
    {
        $overdue = Rfi::query()->overdue()->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['job', 'delayEvent']);
    }

    public static function form(Schema $schema): Schema
    {
        return RfiForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RfisTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRfis::route('/'),
            'create' => CreateRfi::route('/create'),
            'edit' => EditRfi::route('/{record}/edit'),
        ];
    }
}
