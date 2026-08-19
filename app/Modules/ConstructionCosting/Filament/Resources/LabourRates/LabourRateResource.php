<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\LabourRates;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRates\Pages\CreateLabourRate;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRates\Pages\EditLabourRate;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRates\Pages\ListLabourRates;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRates\Schemas\LabourRateForm;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRates\Tables\LabourRatesTable;
use App\Modules\ConstructionCosting\Models\LabourRate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * What an hour costs, from a date — `docs/construction-management-plan.md` §7.2.
 *
 * **A register of dated rows, and the register *is* the design.** §7.2 rejects the obvious alternative in one sentence:
 * "A wage revision effective the first of April must not restate March's job cost. A `cost_rate_per_hour` column on the
 * worker does exactly that, silently, the moment somebody edits it." So there is no rate field on the worker or the
 * trade — there are rows here, each with the dates it applies between.
 *
 * A row's **scope** is which of job, trade and person it names, and that is what makes it more or less specific.
 * §7.2's ladder is `job+trade -> job -> worker/employee -> trade -> company default`, and the *Applies to* column is
 * that ladder made visible: without it, five rows that all look like rates are unreadable.
 */
class LabourRateResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = LabourRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?int $navigationSort = 52;

    protected static ?string $label = 'Labour rate';

    protected static ?string $pluralLabel = 'Labour rates';

    public static function getEloquentQuery(): Builder
    {
        // Every row prints what it applies to, which reads the job, the trade and the worker (§18.3).
        return parent::getEloquentQuery()->with(['job', 'trade', 'worker']);
    }

    public static function form(Schema $schema): Schema
    {
        return LabourRateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LabourRatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLabourRates::route('/'),
            'create' => CreateLabourRate::route('/create'),
            'edit' => EditLabourRate::route('/{record}/edit'),
        ];
    }
}
