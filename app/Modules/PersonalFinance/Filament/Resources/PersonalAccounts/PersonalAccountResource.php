<?php

namespace App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\Pages\CreatePersonalAccount;
use App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\Pages\EditPersonalAccount;
use App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\Pages\ListPersonalAccounts;
use App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\Schemas\PersonalAccountForm;
use App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\Tables\PersonalAccountsTable;
use App\Modules\PersonalFinance\Models\PersonalAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * A person's own chart of accounts: where their money sits, what they owe, and
 * the categories their income and spending are booked against.
 *
 * No getEloquentQuery() override scoping to the owner, and that is deliberate —
 * PersonalAccount carries a global scope, so every query is already restricted,
 * including the ones Filament builds for the table, the paginator, global search
 * and record resolution. Doing it here as well would suggest the scope is
 * optional.
 */
class PersonalAccountResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = PersonalAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'Personal';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Account';

    protected static ?string $pluralModelLabel = 'My Accounts';

    public static function form(Schema $schema): Schema
    {
        return PersonalAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PersonalAccountsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPersonalAccounts::route('/'),
            'create' => CreatePersonalAccount::route('/create'),
            'edit' => EditPersonalAccount::route('/{record}/edit'),
        ];
    }
}
