<?php

namespace App\Modules\Accounting\Filament\Resources\Accounts;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Modules\Accounting\Filament\Resources\Accounts\Pages\EditAccount;
use App\Modules\Accounting\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Modules\Accounting\Filament\Resources\Accounts\RelationManagers\ChildrenRelationManager;
use App\Modules\Accounting\Filament\Resources\Accounts\RelationManagers\LinesRelationManager;
use App\Modules\Accounting\Filament\Resources\Accounts\Schemas\AccountForm;
use App\Modules\Accounting\Filament\Resources\Accounts\Tables\AccountsTable;
use App\Modules\Accounting\Models\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AccountResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $recordTitleAttribute = 'name';

    // Nova label() → "Chart of Accounts".
    protected static ?string $modelLabel = 'Account';

    protected static ?string $pluralModelLabel = 'Chart of Accounts';

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name'];
    }

    public static function getEloquentQuery(): Builder
    {
        // Nova indexQuery() reorders by code when no explicit order is set.
        return parent::getEloquentQuery()->orderBy('code');
    }

    public static function form(Schema $schema): Schema
    {
        return AccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ChildrenRelationManager::class,
            LinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccounts::route('/'),
            'create' => CreateAccount::route('/create'),
            'edit' => EditAccount::route('/{record}/edit'),
        ];
    }
}
