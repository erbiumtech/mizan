<?php

namespace App\Filament\Resources\MPRs;

use App\Filament\Resources\MPRs\Pages\CreateMPR;
use App\Filament\Resources\MPRs\Pages\EditMPR;
use App\Filament\Resources\MPRs\Pages\ListMPRS;
use App\Filament\Resources\MPRs\Schemas\MPRForm;
use App\Filament\Resources\MPRs\Tables\MPRSTable;
use App\Models\MPR;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class MPRResource extends Resource
{
    protected static ?string $model = MPR::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'MPR';

    protected static ?string $modelLabel = 'MPR';

    protected static ?string $pluralModelLabel = 'MPR';

    protected static ?string $recordTitleAttribute = 'id';

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'user.name'];
    }

    /**
     * Nova indexQuery parity: Administrators see all; others are scoped to their own records.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();

        if ($user && $user->hasRole('Administrator')) {
            return $query;
        }

        return $query->where('user_id', $user?->id);
    }

    public static function form(Schema $schema): Schema
    {
        return MPRForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MPRSTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMPRS::route('/'),
            'create' => CreateMPR::route('/create'),
            'edit' => EditMPR::route('/{record}/edit'),
        ];
    }
}
