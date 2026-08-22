<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\RelationManagers;

use App\Modules\ConstructionContracts\Models\CertificateDeduction;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Services\CertificationService;
use App\Modules\ConstructionContracts\Services\NcrDeductionOffer;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

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
    /** @var Collection<int, \App\Modules\ConstructionQhse\Models\Ncr>|null */
    private ?Collection $ncrOffers = null;

    protected static string $relationship = 'deductions';

    protected static ?string $title = 'Deductions';

    private function isDraft(): bool
    {
        /** @var PaymentCertificate $certificate */
        $certificate = $this->getOwnerRecord();

        return $certificate->isDraft();
    }

    /**
     * The proposals on offer for this certificate's contract.
     *
     * A read, and it is memoised per request because the action asks for it three times — in `visible()`, in the badge
     * and in the picker — and a relation manager renders more than once.
     *
     * @return Collection<int, \App\Modules\ConstructionQhse\Models\Ncr>
     */
    private function offers(): Collection
    {
        /** @var PaymentCertificate $certificate */
        $certificate = $this->getOwnerRecord();

        // By key rather than off the relation: reading `$certificate->contract` on a record the relation manager
        // fetched without it is a lazy load, which this application refuses.
        $contract = Contract::query()->find($certificate->contract_id);

        return $this->ncrOffers ??= $contract === null
            ? collect()
            : app(NcrDeductionOffer::class)->offersFor($contract);
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

                /*
                 * **Non-conformance proposals, offered — §17.2, Phase 10b.**
                 *
                 * An NCR proposes a withholding and never applies one. This action is where a human takes one up: the
                 * amount is editable because the ordinary outcome of a conversation about it is withholding *less* than
                 * the quality team assessed, and a version that copied the figure would make that conversation
                 * unrecordable.
                 *
                 * The row it writes is `is_automatic = false` with the taker's name on it, which is precisely the
                 * distinction §17.2 is written around: "a deduction appearing on a certificate that nobody decided on is
                 * the fastest available route to a dispute."
                 *
                 * Absent without `construction_qhse`, and absent when there is nothing proposed — an empty offer list is
                 * not a button worth showing.
                 */
                Action::make('takeNcrDeduction')
                    ->label('Offered non-conformances')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('warning')
                    ->badge(fn (): ?string => ($count = $this->offers()->count()) > 0 ? (string) $count : null)
                    ->modalHeading('Non-conformances proposing a deduction')
                    ->modalDescription('These are proposals from the quality register. Nothing is withheld until you choose one and confirm the figure — FIDIC 14.6 permits the Engineer to withhold; it does not require it.')
                    ->visible(fn (): bool => $this->isDraft() && $this->offers()->isNotEmpty())
                    ->schema(fn (): array => [
                        Select::make('ncr_id')
                            ->label('Non-conformance')
                            ->options(fn (): array => $this->offers()
                                ->mapWithKeys(fn ($ncr): array => [
                                    $ncr->getKey() => $ncr->ncr_number.' — '.str($ncr->description)->limit(50)
                                        .' ('.number_format((float) $ncr->deduction_amount, 2).' proposed)',
                                ])
                                ->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set): void {
                                $proposed = $this->offers()->firstWhere('id', $state)?->deduction_amount;

                                $set('amount', $proposed === null ? null : (float) $proposed);
                            }),
                        TextInput::make('amount')
                            ->label('Amount to withhold')
                            ->numeric()
                            ->required()
                            ->helperText('Defaults to what was proposed. Withhold less if that is what was agreed — the figure on the certificate is your decision, not the quality team\'s.'),
                        TextInput::make('reason')
                            ->label('Note on the certificate')
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        $offer = $this->offers()->firstWhere('id', $data['ncr_id']);

                        if ($offer === null) {
                            Notification::make()->danger()->title('That proposal is no longer on offer.')->send();

                            return;
                        }

                        try {
                            app(NcrDeductionOffer::class)->take(
                                $this->getOwnerRecord(),
                                $offer,
                                (float) $data['amount'],
                                $data['reason'] ?? null,
                            );
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                            return;
                        }

                        app(CertificationService::class)->recompute($this->getOwnerRecord()->refresh());

                        Notification::make()
                            ->success()
                            ->title('Withheld, with your name against it.')
                            ->body('The non-conformance now records which certificate took the proposal up.')
                            ->send();
                    }),
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
