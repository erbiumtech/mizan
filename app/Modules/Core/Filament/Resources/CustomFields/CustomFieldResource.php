<?php

namespace App\Modules\Core\Filament\Resources\CustomFields;

use App\Modules\Core\Filament\Resources\CustomFields\Pages\CreateCustomField;
use App\Modules\Core\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Modules\Core\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Modules\Core\Filament\Resources\CustomFields\Schemas\CustomFieldForm;
use App\Modules\Core\Filament\Resources\CustomFields\Tables\CustomFieldsTable;
use App\Modules\Core\Models\CustomField;
use App\Support\CustomFieldSubjects;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CustomFieldResource extends Resource
{
    /**
     * The models a custom field may be defined on, as `alias => label`.
     *
     * This was a `const MODELS` naming six classes from four modules, mapped through
     * `ModuleMap::alias()` on the way out. Each module now registers its own subjects
     * (App\Support\CustomFieldSubjects), so this screen renders whatever is installed and names none of
     * them — see docs/module-packaging-plan.md §9. The aliases are unchanged, so every existing definition
     * still resolves.
     *
     * @return array<string, string>
     */
    public static function modelOptions(): array
    {
        return CustomFieldSubjects::options();
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
