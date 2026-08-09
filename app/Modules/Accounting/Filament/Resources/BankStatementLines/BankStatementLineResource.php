<?php

namespace App\Modules\Accounting\Filament\Resources\BankStatementLines;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Filament\Resources\BankStatementLines\Pages\CreateBankStatementLine;
use App\Modules\Accounting\Filament\Resources\BankStatementLines\Pages\EditBankStatementLine;
use App\Modules\Accounting\Filament\Resources\BankStatementLines\Pages\ListBankStatementLines;
use App\Modules\Accounting\Filament\Resources\BankStatementLines\Schemas\BankStatementLineForm;
use App\Modules\Accounting\Filament\Resources\BankStatementLines\Tables\BankStatementLinesTable;
use App\Modules\Accounting\Models\BankStatementLine;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BankStatementLineResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = BankStatementLine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $recordTitleAttribute = 'description';

    // Nova: $displayInNavigation = false
    protected static bool $shouldRegisterNavigation = false;

    public static function getGloballySearchableAttributes(): array
    {
        return ['description', 'reference'];
    }

    public static function form(Schema $schema): Schema
    {
        return BankStatementLineForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankStatementLinesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankStatementLines::route('/'),
            'create' => CreateBankStatementLine::route('/create'),
            'edit' => EditBankStatementLine::route('/{record}/edit'),
        ];
    }
}
