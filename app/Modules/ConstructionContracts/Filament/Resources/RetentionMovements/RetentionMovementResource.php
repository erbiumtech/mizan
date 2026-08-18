<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\RetentionMovements;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionContracts\Filament\Resources\RetentionMovements\Pages\ListRetentionMovements;
use App\Modules\ConstructionContracts\Filament\Resources\RetentionMovements\Tables\RetentionMovementsTable;
use App\Modules\ConstructionContracts\Models\RetentionMovement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The retention ledger — `docs/construction-management-plan.md` §11.
 *
 * **A register of events, not a balance.** The balance is their sum, computed. What is stored is what happened: a
 * certificate held money, a taking-over released half of it, a bond substituted for the rest, an uncorrected defect
 * forfeited some. None of those is derivable from the certificates, and a company that has done one of them has a
 * figure nobody can explain at final account unless it is written down.
 *
 * **List-only, and that is the design.** There is no edit page and no delete: a movement is an event, and the way
 * to correct one is another movement with a reason — the same discipline the cost ledger keeps with reversals.
 * Releasing and forfeiting are actions on this list, and each of them writes a row.
 */
class RetentionMovementResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = RetentionMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Contracts';

    protected static ?string $recordTitleAttribute = 'kind';

    protected static ?int $navigationSort = 50;

    protected static ?string $label = 'Retention';

    protected static ?string $pluralLabel = 'Retention ledger';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['contract.job', 'certificate']);
    }

    public static function table(Table $table): Table
    {
        return RetentionMovementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRetentionMovements::route('/'),
        ];
    }
}
