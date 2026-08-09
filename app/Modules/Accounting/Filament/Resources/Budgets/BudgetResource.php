<?php

namespace App\Modules\Accounting\Filament\Resources\Budgets;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Filament\Resources\Budgets\Pages\CreateBudget;
use App\Modules\Accounting\Filament\Resources\Budgets\Pages\EditBudget;
use App\Modules\Accounting\Filament\Resources\Budgets\Pages\ListBudgets;
use App\Modules\Accounting\Filament\Resources\Budgets\RelationManagers\MonthlyPlanRelationManager;
use App\Modules\Accounting\Filament\Resources\Budgets\Schemas\BudgetForm;
use App\Modules\Accounting\Filament\Resources\Budgets\Tables\BudgetsTable;
use App\Modules\Accounting\Models\Budget;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class BudgetResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Budget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('fiscalYear');
    }

    public static function form(Schema $schema): Schema
    {
        return BudgetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BudgetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MonthlyPlanRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBudgets::route('/'),
            'create' => CreateBudget::route('/create'),
            'edit' => EditBudget::route('/{record}/edit'),
        ];
    }
}
