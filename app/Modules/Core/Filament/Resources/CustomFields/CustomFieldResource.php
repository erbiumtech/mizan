<?php

namespace App\Modules\Core\Filament\Resources\CustomFields;

use App\Support\ModuleMap;
use App\Modules\Core\Filament\Resources\CustomFields\Pages\CreateCustomField;
use App\Modules\Core\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Modules\Core\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Modules\Core\Filament\Resources\CustomFields\Schemas\CustomFieldForm;
use App\Modules\Core\Filament\Resources\CustomFields\Tables\CustomFieldsTable;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Core\Models\CustomField;
use App\Modules\Employees\Models\Employee;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Inventory\Models\Product;
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
        Contact::class => 'Contacts',
        Employee::class => 'Employees',
        Invoice::class => 'Invoices',
        Product::class => 'Products',
        Beneficiary::class => 'Beneficiaries',
        FixedAsset::class => 'Fixed Assets',
    ];

    /**
     * The same list keyed by each model's stable alias, which is what
     * `custom_fields.model_type` stores — a definition must keep pointing at the
     * right model after that model moves into its module directory. Used for the
     * Select options, the filter and the column label, so nothing writes a raw
     * class name into the column.
     *
     * @return array<string, string>
     */
    public static function modelOptions(): array
    {
        $options = [];

        foreach (self::MODELS as $class => $label) {
            $options[ModuleMap::alias($class)] = $label;
        }

        return $options;
    }

    protected static ?string $model = CustomField::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $recordTitleAttribute = 'name';

    /** Field definitions are an Administrator concern, and only when the feature is on. */
    public static function canAccess(): bool
    {
        return config('custom_fields.enabled', true)
            && (auth()->user()?->isAdministrator() ?? false);
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
