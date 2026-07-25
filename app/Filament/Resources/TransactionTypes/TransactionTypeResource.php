<?php

namespace App\Filament\Resources\TransactionTypes;

use App\Filament\Resources\TransactionTypes\Pages\CreateTransactionType;
use App\Filament\Resources\TransactionTypes\Pages\EditTransactionType;
use App\Filament\Resources\TransactionTypes\Pages\ListTransactionTypes;
use App\Filament\Resources\TransactionTypes\RelationManagers\CompanyBankAccountsRelationManager;
use App\Filament\Resources\TransactionTypes\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\TransactionTypes\Schemas\TransactionTypeForm;
use App\Filament\Resources\TransactionTypes\Tables\TransactionTypesTable;
use App\Models\TransactionType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TransactionTypeResource extends Resource
{
    protected static ?string $model = TransactionType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code'];
    }

    public static function form(Schema $schema): Schema
    {
        return TransactionTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TransactionTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
            CompanyBankAccountsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactionTypes::route('/'),
            'create' => CreateTransactionType::route('/create'),
            'edit' => EditTransactionType::route('/{record}/edit'),
        ];
    }
}
