<?php

namespace App\Modules\Construction\Filament\Resources\CostCodes;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Construction\Filament\Resources\CostCodes\Pages\CreateCostCode;
use App\Modules\Construction\Filament\Resources\CostCodes\Pages\EditCostCode;
use App\Modules\Construction\Filament\Resources\CostCodes\Pages\ListCostCodes;
use App\Modules\Construction\Filament\Resources\CostCodes\Schemas\CostCodeForm;
use App\Modules\Construction\Filament\Resources\CostCodes\Tables\CostCodesTable;
use App\Modules\Construction\Models\CostCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The cost-code library — company-wide, shared by every job.
 *
 * §2.2: a job *selects* codes rather than owning them, which is what makes "what did formwork to soffits cost
 * us per square metre across the last six jobs" answerable at all.
 */
class CostCodeResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = CostCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('parent');
    }

    public static function form(Schema $schema): Schema
    {
        return CostCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CostCodesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCostCodes::route('/'),
            'create' => CreateCostCode::route('/create'),
            'edit' => EditCostCode::route('/{record}/edit'),
        ];
    }
}
