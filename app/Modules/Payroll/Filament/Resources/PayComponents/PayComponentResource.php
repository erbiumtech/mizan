<?php

namespace App\Modules\Payroll\Filament\Resources\PayComponents;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Payroll\Filament\Resources\PayComponents\Pages\CreatePayComponent;
use App\Modules\Payroll\Filament\Resources\PayComponents\Pages\EditPayComponent;
use App\Modules\Payroll\Filament\Resources\PayComponents\Pages\ListPayComponents;
use App\Modules\Payroll\Filament\Resources\PayComponents\Schemas\PayComponentForm;
use App\Modules\Payroll\Filament\Resources\PayComponents\Tables\PayComponentsTable;
use App\Modules\Payroll\Models\PayComponent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * What pay is made of.
 *
 * Adding an allowance was thirteen files; it is this screen and an amount per
 * employee. The eleven parts the system shipped with are listed here too — they still
 * live in their own columns, which is why they cannot be deleted or reshaped from
 * here, but the list of what pay consists of is now in one place instead of repeated
 * in every form, table, report and seeder that needed it.
 */
class PayComponentResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = PayComponent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?string $modelLabel = 'Pay component';

    public static function form(Schema $schema): Schema
    {
        return PayComponentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PayComponentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayComponents::route('/'),
            'create' => CreatePayComponent::route('/create'),
            'edit' => EditPayComponent::route('/{record}/edit'),
        ];
    }
}
