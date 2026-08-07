<?php

namespace App\Modules\Accounting\Filament\Resources\Loans;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Filament\Resources\Loans\Pages\CreateLoan;
use App\Modules\Accounting\Filament\Resources\Loans\Pages\EditLoan;
use App\Modules\Accounting\Filament\Resources\Loans\Pages\ListLoans;
use App\Modules\Accounting\Filament\Resources\Loans\RelationManagers\ScheduleRelationManager;
use App\Modules\Accounting\Filament\Resources\Loans\Schemas\LoanForm;
use App\Modules\Accounting\Filament\Resources\Loans\Tables\LoansTable;
use App\Modules\Accounting\Models\Loan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class LoanResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Loan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Loans';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'lender'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('liabilityAccount');
    }

    public static function form(Schema $schema): Schema
    {
        return LoanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LoansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ScheduleRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoans::route('/'),
            'create' => CreateLoan::route('/create'),
            'edit' => EditLoan::route('/{record}/edit'),
        ];
    }
}
