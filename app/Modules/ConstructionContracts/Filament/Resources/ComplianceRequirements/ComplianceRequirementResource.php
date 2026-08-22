<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ComplianceRequirements;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceRequirements\Pages\CreateComplianceRequirement;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceRequirements\Pages\EditComplianceRequirement;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceRequirements\Pages\ListComplianceRequirements;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceRequirements\Schemas\ComplianceRequirementForm;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceRequirements\Tables\ComplianceRequirementsTable;
use App\Modules\ConstructionContracts\Models\ComplianceRequirement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * What subcontractors have to produce, and what its absence stops — `docs/construction-management-plan.md` §12.
 *
 * **This screen is what makes the register a control rather than a filing cabinet.** The documents resource records what
 * has arrived; this one records what is required, and a requirement that blocks certification is what refuses the next
 * certificate.
 *
 * A requirement with **no contract** is the company template — required of every subcontract unless one of them says
 * otherwise. Without it, every contract restates the same insurance list and the one that mattered is the one somebody
 * forgets.
 */
class ComplianceRequirementResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ComplianceRequirement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Contracts';

    protected static ?string $recordTitleAttribute = 'kind';

    protected static ?int $navigationSort = 61;

    protected static ?string $label = 'Compliance requirement';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('contract.job');
    }

    public static function form(Schema $schema): Schema
    {
        return ComplianceRequirementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ComplianceRequirementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComplianceRequirements::route('/'),
            'create' => CreateComplianceRequirement::route('/create'),
            'edit' => EditComplianceRequirement::route('/{record}/edit'),
        ];
    }
}
