<?php

namespace App\Modules\Accounting\Filament\Resources\FixedAssets;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Filament\Resources\FixedAssets\Pages\CreateFixedAsset;
use App\Modules\Accounting\Filament\Resources\FixedAssets\Pages\EditFixedAsset;
use App\Modules\Accounting\Filament\Resources\FixedAssets\Pages\ListFixedAssets;
use App\Modules\Accounting\Filament\Resources\FixedAssets\RelationManagers\JournalEntriesRelationManager;
use App\Modules\Accounting\Filament\Resources\FixedAssets\Schemas\FixedAssetForm;
use App\Modules\Accounting\Filament\Resources\FixedAssets\Tables\FixedAssetsTable;
use App\Modules\Accounting\Models\FixedAsset;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class FixedAssetResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = FixedAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['asset_code', 'name'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('customFieldValues.customField');
    }

    public static function form(Schema $schema): Schema
    {
        return FixedAssetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FixedAssetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            JournalEntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFixedAssets::route('/'),
            'create' => CreateFixedAsset::route('/create'),
            'edit' => EditFixedAsset::route('/{record}/edit'),
        ];
    }
}
