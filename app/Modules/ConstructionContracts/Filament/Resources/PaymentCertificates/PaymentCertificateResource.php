<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Pages\CreatePaymentCertificate;
use App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Pages\EditPaymentCertificate;
use App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Pages\ListPaymentCertificates;
use App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\RelationManagers\CertificateDeductionsRelationManager;
use App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\RelationManagers\CertificateLinesRelationManager;
use App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Schemas\PaymentCertificateForm;
use App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Tables\PaymentCertificatesTable;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * What the certifier issues — `docs/construction-management-plan.md` §10.
 *
 * **A draft computes and an issued certificate is frozen.** The register makes that visible rather than implicit:
 * a draft carries a *recompute* action and editable deductions, and an issued one carries neither. The only ways
 * back are to void it with a reason or to let the next certificate absorb the difference, which the cumulative
 * design makes automatic.
 *
 * The bottom half of the certificate lives in the **deductions** tab, one row each, because that is what makes an
 * NCR deduction traceable to its NCR and advance recovery auditable against the contract terms rather than being
 * a number in a memo field (§10.3).
 */
class PaymentCertificateResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = PaymentCertificate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Contracts';

    protected static ?string $recordTitleAttribute = 'certificate_number';

    protected static ?int $navigationSort = 40;

    protected static ?string $label = 'Payment certificate';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['contract.job', 'progressClaim']);
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentCertificateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentCertificatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CertificateLinesRelationManager::class,
            CertificateDeductionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentCertificates::route('/'),
            'create' => CreatePaymentCertificate::route('/create'),
            'edit' => EditPaymentCertificate::route('/{record}/edit'),
        ];
    }
}
