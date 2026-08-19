<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ComplianceDocuments;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceDocuments\Pages\CreateComplianceDocument;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceDocuments\Pages\EditComplianceDocument;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceDocuments\Pages\ListComplianceDocuments;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceDocuments\Schemas\ComplianceDocumentForm;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceDocuments\Tables\ComplianceDocumentsTable;
use App\Modules\ConstructionContracts\Models\ComplianceDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Subcontractor insurance, licences, waivers and bonds — `docs/construction-management-plan.md` §12.
 *
 * **There is no status column in the table and none on this screen's rows either.** Status is computed from the dates
 * every time it is read, because §12 names a stored one as the most dangerous silent failure on the payable side: a row
 * saying `verified` with an expiry three months past pays a subcontractor with no cover while the screen looks fine.
 *
 * What this register does that a folder of PDFs cannot is stop a payment. A requirement that blocks certification will
 * refuse the next certificate against that subcontract until the document is produced — or until somebody with the
 * override permission certifies anyway and says why.
 */
class ComplianceDocumentResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ComplianceDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Contracts';

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?int $navigationSort = 60;

    protected static ?string $label = 'Compliance document';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['contract.job', 'document']);
    }

    public static function form(Schema $schema): Schema
    {
        return ComplianceDocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ComplianceDocumentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComplianceDocuments::route('/'),
            'create' => CreateComplianceDocument::route('/create'),
            'edit' => EditComplianceDocument::route('/{record}/edit'),
        ];
    }
}
