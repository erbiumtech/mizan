<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Trades;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionCosting\Filament\Resources\Trades\Pages\CreateTrade;
use App\Modules\ConstructionCosting\Filament\Resources\Trades\Pages\EditTrade;
use App\Modules\ConstructionCosting\Filament\Resources\Trades\Pages\ListTrades;
use App\Modules\ConstructionCosting\Filament\Resources\Trades\Schemas\TradeForm;
use App\Modules\ConstructionCosting\Filament\Resources\Trades\Tables\TradesTable;
use App\Modules\ConstructionCosting\Models\Trade;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The trades this company employs — `docs/construction-management-plan.md` §7.1.
 *
 * Reference data shared by every job, like the cost-code library and for the same reason (§2.2): "what does an hour of
 * steel fixing cost us" is asked across jobs, and it stops having an answer the moment each job invents its own list.
 *
 * **No rate lives here.** The trade's default rate is a row in the Labour Rates register with this trade named and
 * nothing else — §7.2's company-default tier — because a rate column on this screen would restate every month's
 * labour cost the moment somebody edited it.
 */
class TradeResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Trade::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 50;

    protected static ?string $label = 'Trade';

    protected static ?string $pluralLabel = 'Trades';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('defaultCostCode');
    }

    public static function form(Schema $schema): Schema
    {
        return TradeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TradesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrades::route('/'),
            'create' => CreateTrade::route('/create'),
            'edit' => EditTrade::route('/{record}/edit'),
        ];
    }
}
