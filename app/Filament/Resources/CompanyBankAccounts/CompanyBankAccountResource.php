<?php

namespace App\Filament\Resources\CompanyBankAccounts;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\CompanyBankAccounts\Pages\CreateCompanyBankAccount;
use App\Filament\Resources\CompanyBankAccounts\Pages\EditCompanyBankAccount;
use App\Filament\Resources\CompanyBankAccounts\Pages\ListCompanyBankAccounts;
use App\Filament\Resources\CompanyBankAccounts\Schemas\CompanyBankAccountForm;
use App\Filament\Resources\CompanyBankAccounts\Tables\CompanyBankAccountsTable;
use App\Models\CompanyBankAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CompanyBankAccountResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = CompanyBankAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'account_no', 'iban'];
    }

    public static function form(Schema $schema): Schema
    {
        return CompanyBankAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompanyBankAccountsTable::configure($table);
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
            'index' => ListCompanyBankAccounts::route('/'),
            'create' => CreateCompanyBankAccount::route('/create'),
            'edit' => EditCompanyBankAccount::route('/{record}/edit'),
        ];
    }
}
