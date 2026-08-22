<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Commitments;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionCosting\Filament\Resources\Commitments\Pages\CreateCommitment;
use App\Modules\ConstructionCosting\Filament\Resources\Commitments\Pages\EditCommitment;
use App\Modules\ConstructionCosting\Filament\Resources\Commitments\Pages\ListCommitments;
use App\Modules\ConstructionCosting\Filament\Resources\Commitments\RelationManagers\CommitmentLinesRelationManager;
use App\Modules\ConstructionCosting\Filament\Resources\Commitments\Schemas\CommitmentForm;
use App\Modules\ConstructionCosting\Filament\Resources\Commitments\Tables\CommitmentsTable;
use App\Modules\ConstructionCosting\Models\Commitment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Purchase orders, subcontract orders and plant hire — `docs/construction-management-plan.md` §5.
 *
 * **One register for all three**, because the four-column report and the relief mechanism have to behave
 * identically for each or "committed" means different things in one column.
 *
 * The screen makes the distinction that matters visible: an order is **approved** when this company decides to
 * spend the money and **issued** when the supplier is told, and only the second is a commitment. Two buttons, not
 * one, because an approved order sitting in a drawer can still be withdrawn with a phone call.
 */
class CommitmentResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Commitment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 45;

    protected static ?string $label = 'Order';

    protected static ?string $pluralLabel = 'Orders and commitments';

    public static function getEloquentQuery(): Builder
    {
        // The register totals ordered against open on every row, and both are folds over the lines and their
        // reliefs — so both are loaded once here rather than per row (§18.3's query budget).
        return parent::getEloquentQuery()->with(['lines.reliefs']);
    }

    public static function form(Schema $schema): Schema
    {
        return CommitmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CommitmentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [CommitmentLinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommitments::route('/'),
            'create' => CreateCommitment::route('/create'),
            'edit' => EditCommitment::route('/{record}/edit'),
        ];
    }
}
