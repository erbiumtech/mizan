<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\Contracts;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionContracts\Filament\Resources\Contracts\Pages\CreateContract;
use App\Modules\ConstructionContracts\Filament\Resources\Contracts\Pages\EditContract;
use App\Modules\ConstructionContracts\Filament\Resources\Contracts\Pages\ListContracts;
use App\Modules\ConstructionContracts\Filament\Resources\Contracts\RelationManagers\ItemsRelationManager;
use App\Modules\ConstructionContracts\Filament\Resources\Contracts\Schemas\ContractForm;
use App\Modules\ConstructionContracts\Filament\Resources\Contracts\Tables\ContractsTable;
use App\Modules\ConstructionContracts\Models\Contract;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The head contract and the subcontract, one register — `docs/construction-management-plan.md` §8.
 *
 * **Both sides in one place on purpose.** `side` is the only structural difference between what we bill the
 * employer and what we pay a subcontractor, and the commercial question people actually ask — "what have we
 * committed downward against what we have secured upward" — is unanswerable across two screens.
 *
 * Everything the standard drives is words, and the words come from `ContractVocabulary` (§8.3). Nothing in
 * this resource matches on the standard itself.
 */
class ContractResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Contract::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Contracts';

    protected static ?string $recordTitleAttribute = 'contract_number';

    protected static ?int $navigationSort = 10;

    protected static ?string $label = 'Contract';

    public static function getEloquentQuery(): Builder
    {
        // The register shows the job and the other party on every row.
        return parent::getEloquentQuery()->with(['job', 'contact']);
    }

    public static function form(Schema $schema): Schema
    {
        return ContractForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContractsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContracts::route('/'),
            'create' => CreateContract::route('/create'),
            'edit' => EditContract::route('/{record}/edit'),
        ];
    }
}
