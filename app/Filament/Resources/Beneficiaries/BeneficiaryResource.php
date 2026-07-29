<?php

namespace App\Filament\Resources\Beneficiaries;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\Beneficiaries\Pages\CreateBeneficiary;
use App\Filament\Resources\Beneficiaries\Pages\EditBeneficiary;
use App\Filament\Resources\Beneficiaries\Pages\ListBeneficiaries;
use App\Filament\Resources\Beneficiaries\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Beneficiaries\Schemas\BeneficiaryForm;
use App\Filament\Resources\Beneficiaries\Tables\BeneficiariesTable;
use App\Models\Beneficiary;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class BeneficiaryResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Beneficiary::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'account_no', 'iban', 'id_number'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('customFieldValues.customField');
    }

    public static function form(Schema $schema): Schema
    {
        return BeneficiaryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BeneficiariesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBeneficiaries::route('/'),
            'create' => CreateBeneficiary::route('/create'),
            'edit' => EditBeneficiary::route('/{record}/edit'),
        ];
    }
}
