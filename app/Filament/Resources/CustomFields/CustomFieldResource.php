<?php

namespace App\Filament\Resources\CustomFields;

use App\Filament\Resources\CustomFields\Pages\CreateCustomField;
use App\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Filament\Resources\CustomFields\Schemas\CustomFieldForm;
use App\Filament\Resources\CustomFields\Tables\CustomFieldsTable;
use App\Models\CustomField;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CustomFieldResource extends Resource
{
    /** Domain models that can have custom fields (extend as more opt in). */
    public const MODELS = [
        \App\Models\Contact::class => 'Contacts',
        \App\Models\Employee::class => 'Employees',
        \App\Models\Invoice::class => 'Invoices',
        \App\Models\Product::class => 'Products',
        \App\Models\Beneficiary::class => 'Beneficiaries',
        \App\Models\FixedAsset::class => 'Fixed Assets',
    ];

    protected static ?string $model = CustomField::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $recordTitleAttribute = 'name';

    /** Field definitions are an Administrator concern. */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Administrator') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return CustomFieldForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomFieldsTable::configure($table);
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
            'index' => ListCustomFields::route('/'),
            'create' => CreateCustomField::route('/create'),
            'edit' => EditCustomField::route('/{record}/edit'),
        ];
    }
}
