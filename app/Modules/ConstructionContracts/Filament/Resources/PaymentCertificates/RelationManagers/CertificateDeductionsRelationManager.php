<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\RelationManagers;

use App\Modules\ConstructionContracts\Models\CertificateDeduction;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Services\CertificationService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The bottom half of the certificate, one row per deduction — `docs/construction-management-plan.md` §10.3.
 *
 * **A negative amount reduces the payment. One convention, stated once.** The form says so, the table colours it,
 * and the service writes its automatic rows the same way.
 *
 * Retention, advance recovery and "less previously certified" are **computed** and marked automatic; they cannot
 * be added by hand, because a second retention row on one certificate is a double deduction nobody notices until
 * the other party does. What a person adds is a decision: an NCR deduction, liquidated damages, a back-charge.
 */
class CertificateDeductionsRelationManager extends RelationManager
{
    protected static string $relationship = 'deductions';

    protected static ?string $title = 'Deductions';

    private function isDraft(): bool
    {
        /** @var PaymentCertificate $certificate */
        $certificate = $this->getOwnerRecord();

        return $certificate->isDraft();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('kind')
                    ->label('Kind')
                    // The automatic kinds are absent from this list rather than disabled: an option that always
                    // refuses is one people choose and then complain about.
                    ->options([
                        CertificateDeduction::KIND_NCR => 'Non-conformance',
                        CertificateDeduction::KIND_LIQUIDATED_DAMAGES => 'Liquidated damages',
                        CertificateDeduction::KIND_BACK_CHARGE => 'Back-charge',
                        CertificateDeduction::KIND_CONTRA_CHARGE => 'Contra-charge',
                        CertificateDeduction::KIND_TAX_WITHHELD => 'Tax withheld',
                        CertificateDeduction::KIND_UNFIXED_MATERIALS => 'Unfixed materials adjustment',
                        CertificateDeduction::KIND_RETENTION_RELEASE => 'Retention release',
                        CertificateDeduction::KIND_OTHER => 'Other',
                    ])
                    ->required()
                    ->searchable(),

                TextInput::make('description')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Printed on the certificate — name the clause and the reference.'),

                TextInput::make('amount')
                    ->numeric()
                    ->required()
                    ->helperText('Negative reduces the payment. A retention release is positive.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state))),

                TextColumn::make('description')
                    ->wrap(),

                TextColumn::make('amount')
                    ->money('PKR')
                    ->alignEnd()
                    ->color(fn (CertificateDeduction $record): string => $record->reducesPayment() ? 'danger' : 'success')
                    ->summarize(Sum::make()->money('PKR')),

                IconColumn::make('is_automatic')
                    ->label('Computed')
                    ->boolean()
                    ->tooltip('Computed from the contract terms, and rewritten on every recompute.'),

                TextColumn::make('source_type')
                    ->label('From')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : class_basename($state))
                    ->toggleable(),
            ])
            ->defaultSort('id')
            ->headerActions([
                CreateAction::make()
                    ->label('Add deduction')
                    ->visible(fn (): bool => $this->isDraft())
                    // Through the service, which refuses the computed kinds and recomputes the bottom line.
                    ->using(fn (array $data): Model => app(CertificationService::class)
                        ->addDeduction($this->getOwnerRecord(), $data)),
            ])
            ->recordActions([
                DeleteAction::make()
                    // Automatic rows are the service's to manage; deleting one by hand would leave the header
                    // disagreeing with the bottom line until the next recompute.
                    ->visible(fn (CertificateDeduction $record): bool => $this->isDraft() && ! $record->is_automatic)
                    ->after(fn () => app(CertificationService::class)->recompute($this->getOwnerRecord()->refresh())),
            ])
            ->emptyStateHeading('No deductions')
            ->emptyStateDescription('Retention, advance recovery and previously certified appear here when the certificate is recomputed.');
    }
}
