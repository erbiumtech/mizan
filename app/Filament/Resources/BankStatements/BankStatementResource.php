<?php

namespace App\Filament\Resources\BankStatements;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\BankStatements\Pages\CreateBankStatement;
use App\Filament\Resources\BankStatements\Pages\EditBankStatement;
use App\Filament\Resources\BankStatements\Pages\ListBankStatements;
use App\Filament\Resources\BankStatements\RelationManagers\LinesRelationManager;
use App\Filament\Resources\BankStatements\Schemas\BankStatementForm;
use App\Filament\Resources\BankStatements\Tables\BankStatementsTable;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class BankStatementResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = BankStatement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $recordTitleAttribute = 'id';

    public static function getGloballySearchableAttributes(): array
    {
        return ['id'];
    }

    public static function getEloquentQuery(): Builder
    {
        // Line + matched counts as aggregates (avoids 3 queries per row).
        return parent::getEloquentQuery()
            ->withCount([
                'lines',
                'lines as matched_count' => fn ($q) => $q->whereIn('match_status', [
                    BankStatementLine::STATUS_AUTO_MATCHED,
                    BankStatementLine::STATUS_MANUALLY_MATCHED,
                ]),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return BankStatementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankStatementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankStatements::route('/'),
            'create' => CreateBankStatement::route('/create'),
            'edit' => EditBankStatement::route('/{record}/edit'),
        ];
    }
}
