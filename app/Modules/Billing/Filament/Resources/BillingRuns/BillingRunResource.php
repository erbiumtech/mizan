<?php

namespace App\Modules\Billing\Filament\Resources\BillingRuns;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Billing\Filament\Resources\BillingRuns\Pages\CreateBillingRun;
use App\Modules\Billing\Filament\Resources\BillingRuns\Pages\EditBillingRun;
use App\Modules\Billing\Filament\Resources\BillingRuns\Pages\ListBillingRuns;
use App\Modules\Billing\Filament\Resources\BillingRuns\Schemas\BillingRunForm;
use App\Modules\Billing\Filament\Resources\BillingRuns\Tables\BillingRunsTable;
use App\Modules\Billing\Models\BillingRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BillingRunResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = BillingRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyEuro;

    protected static string|UnitEnum|null $navigationGroup = 'Invoicing & Inventory';

    protected static ?string $recordTitleAttribute = 'month';

    protected static ?string $modelLabel = 'Monthly bill';

    protected static ?string $pluralModelLabel = 'Monthly billing';

    public static function form(Schema $schema): Schema
    {
        return BillingRunForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BillingRunsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBillingRuns::route('/'),
            'create' => CreateBillingRun::route('/create'),
            'edit' => EditBillingRun::route('/{record}/edit'),
        ];
    }
}
