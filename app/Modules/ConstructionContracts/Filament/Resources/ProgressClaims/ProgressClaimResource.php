<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\Pages\CreateProgressClaim;
use App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\Pages\EditProgressClaim;
use App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\Pages\ListProgressClaims;
use App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\RelationManagers\ClaimLinesRelationManager;
use App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\Schemas\ProgressClaimForm;
use App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\Tables\ProgressClaimsTable;
use App\Modules\ConstructionContracts\Models\ProgressClaim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * What the contractor submits — a Statement under FIDIC, an Application for Payment under AIA (§10.1).
 *
 * Its own register rather than a tab on the certificate, because the two documents have different authors and
 * different dates, and because **applied versus certified** — the figure every commercial manager asks for — only
 * exists if the applied number survives somewhere the certified one cannot overwrite.
 */
class ProgressClaimResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ProgressClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Contracts';

    protected static ?string $recordTitleAttribute = 'claim_number';

    protected static ?int $navigationSort = 30;

    protected static ?string $label = 'Progress claim';

    public static function getEloquentQuery(): Builder
    {
        // `certificate` is on every row: the applied-versus-certified column is the reason this register exists.
        return parent::getEloquentQuery()->with(['contract.job', 'certificate']);
    }

    public static function form(Schema $schema): Schema
    {
        return ProgressClaimForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProgressClaimsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ClaimLinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProgressClaims::route('/'),
            'create' => CreateProgressClaim::route('/create'),
            'edit' => EditProgressClaim::route('/{record}/edit'),
        ];
    }
}
