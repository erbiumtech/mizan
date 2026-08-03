<?php

namespace App\Modules\Advances\Filament\Resources\Advances;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Advances\Filament\Resources\Advances\Pages\CreateAdvance;
use App\Modules\Advances\Filament\Resources\Advances\Pages\EditAdvance;
use App\Modules\Advances\Filament\Resources\Advances\Pages\ListAdvances;
use App\Modules\Advances\Filament\Resources\Advances\Schemas\AdvanceForm;
use App\Modules\Advances\Filament\Resources\Advances\Tables\AdvancesTable;
use App\Modules\Advances\Models\Advance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AdvanceResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Advance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $modelLabel = 'Advance';

    protected static ?string $pluralModelLabel = 'Advances';

    public static function form(Schema $schema): Schema
    {
        return AdvanceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdvancesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdvances::route('/'),
            'create' => CreateAdvance::route('/create'),
            'edit' => EditAdvance::route('/{record}/edit'),
        ];
    }
}
