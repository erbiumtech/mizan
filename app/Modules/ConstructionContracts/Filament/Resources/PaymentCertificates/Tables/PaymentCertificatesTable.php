<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Tables;

use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Services\CertificateInvoiceService;
use App\Modules\ConstructionContracts\Services\CertificationService;
use App\Modules\ConstructionContracts\Services\ComplianceService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * The certificate register.
 *
 * Three columns are worth explaining, and each is a place where a plausible-looking figure would be wrong:
 *
 *  - **Gross to date** is cumulative and **this period** is derived from it. That direction is what makes a
 *    corrected earlier certificate self-healing rather than compounding.
 *  - **Retention held** is the cumulative figure the form's total-retainage line prints, while the deduction row
 *    inside the certificate carries only this period's movement.
 *  - **Due** is computed while the certificate is a draft and read off the frozen column once issued. A single
 *    always-computed figure would restate a certificate the other party has already countersigned.
 */
class PaymentCertificatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('certificate_number')
                    ->label('Number')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contract.contract_number')
                    ->label('Contract')
                    ->description(fn (PaymentCertificate $record): ?string => $record->contract?->job?->code)
                    ->toggleable(),

                TextColumn::make('period_end')
                    ->label('Valued to')
                    ->date()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PaymentCertificate::STATUS_ISSUED => 'success',
                        PaymentCertificate::STATUS_PAID => 'info',
                        PaymentCertificate::STATUS_VOID => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('gross_value_to_date')
                    ->label('Gross to date')
                    ->money('PKR')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('this_period')
                    ->label('This period')
                    ->money('PKR')
                    ->alignEnd()
                    ->state(fn (PaymentCertificate $record): float => $record->grossThisPeriod())
                    ->tooltip('Gross to date less previously certified — derived, never stored.'),

                TextColumn::make('retention_to_date')
                    ->label('Retention held')
                    ->money('PKR')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('current_due')
                    ->label('Due')
                    ->money('PKR')
                    ->alignEnd()
                    // Computed on a draft, frozen once issued — the whole rule, in one column.
                    ->state(fn (PaymentCertificate $record): float => $record->currentDue())
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('due_on')
                    ->label('Payable by')
                    ->date()
                    ->placeholder('—')
                    ->tooltip('Issue date plus the contract payment terms.')
                    ->toggleable(),

                TextColumn::make('invoice_id')
                    ->label('Invoiced')
                    ->formatStateUsing(fn ($state): string => $state ? 'Yes' : 'No')
                    ->toggleable(isToggledHiddenByDefault: true),

                /*
                 * Whether this one was certified past a compliance block (§12).
                 *
                 * Visible by default rather than hidden behind the toggle: an override recorded where nobody looks is
                 * an override nobody is accountable for, which is the state the reason field exists to prevent.
                 */
                TextColumn::make('compliance_override_at')
                    ->label('Compliance')
                    ->badge()
                    ->color('warning')
                    /*
                     * Overridden, or nothing at all. Deliberately not "in order" on the other rows: a draft that has
                     * not been overridden may still be blocked — it simply has not been refused yet — and a badge
                     * saying otherwise would be the stored status §12 spent this whole module avoiding.
                     */
                    ->state(fn (PaymentCertificate $record): ?string => $record->compliance_override_at
                        ? 'Overridden'
                        : null)
                    ->placeholder('—')
                    ->tooltip(fn (PaymentCertificate $record): ?string => $record->compliance_override_reason),
            ])
            ->filters([
                SelectFilter::make('contract_id')
                    ->label('Contract')
                    ->relationship('contract', 'contract_number')
                    ->searchable(),

                SelectFilter::make('status')
                    ->options([
                        PaymentCertificate::STATUS_DRAFT => 'Draft',
                        PaymentCertificate::STATUS_ISSUED => 'Issued',
                        PaymentCertificate::STATUS_PAID => 'Paid',
                        PaymentCertificate::STATUS_VOID => 'Void',
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (PaymentCertificate $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('recompute')
                    ->label('Recompute')
                    ->icon('heroicon-o-arrow-path')
                    ->modalHeading('Recompute this draft')
                    ->modalDescription('Re-reads the schedule, the agreed variations and the contract terms, and rewrites the automatic deductions. Manual deductions are left alone.')
                    ->requiresConfirmation()
                    ->visible(fn (PaymentCertificate $record): bool => $record->isDraft()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(fn (PaymentCertificate $record) => static::run(
                        fn () => app(CertificationService::class)->recompute($record),
                        'Recomputed.',
                        'The draft matches the schedule and the contract terms.',
                    )),

                Action::make('certify')
                    ->label('Certify')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Certify and issue')
                    ->modalDescription('The figures freeze, the payment period starts, and the other party gets a document they will rely on. Correcting it afterwards means voiding it or letting the next certificate absorb the difference.')
                    ->schema([
                        DatePicker::make('issued_on')
                            ->label('Issued on')
                            ->default(now())
                            ->helperText('The payment due date is computed from this and the contract terms.'),
                    ])
                    ->visible(fn (PaymentCertificate $record): bool => auth()->user()?->can('certify', $record) ?? false)
                    ->action(fn (PaymentCertificate $record, array $data) => static::run(
                        fn () => app(CertificationService::class)->issue($record, $data['issued_on'] ?? null),
                        'Certified.',
                        'The figures are frozen and the payment period has started.',
                    )),

                /*
                 * §12's override, offered only where something is actually blocking.
                 *
                 * A permanently visible override is an override people reach for out of habit; one that appears when
                 * the refusal appears is a decision taken about a known risk. The reason is mandatory and is recorded
                 * against this certificate alone — it clears nothing later.
                 */
                Action::make('overrideCompliance')
                    ->label('Override compliance')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('warning')
                    ->modalHeading('Certify despite the compliance block')
                    ->modalDescription('This says the company will pay a subcontractor whose paperwork is not in order. It applies to this certificate only, and the reason is kept against it.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->rows(3)
                            ->required()
                            ->helperText('Read by whoever asks later why this payment went out. Write the sentence that answers them.'),
                    ])
                    ->visible(fn (PaymentCertificate $record): bool => (auth()->user()?->can('overrideCompliance', $record) ?? false)
                        && ! app(ComplianceService::class)->permitsCertification($record))
                    ->action(fn (PaymentCertificate $record, array $data) => static::run(
                        fn () => app(ComplianceService::class)->override($record, $data['reason']),
                        'Override recorded.',
                        'Your name, the time and the reason are on this certificate. It may now be certified.',
                    )),

                Action::make('print')
                    ->label('Print')
                    ->icon('heroicon-o-printer')
                    ->openUrlInNewTab()
                    // A route rather than an action returning a response: a direct URL never consults
                    // `canAccess()`, so the licence gate has to be on the route (§18.2's trap).
                    ->url(fn (PaymentCertificate $record): string => route('construction.certificate.pdf', [
                        'company' => Filament::getTenant()?->slug,
                        'certificate' => $record->getKey(),
                    ]))
                    ->visible(fn (PaymentCertificate $record): bool => auth()->user()?->can('view', $record) ?? false),

                Action::make('invoice')
                    ->label('Raise invoice')
                    ->icon('heroicon-o-receipt-percent')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Raise the draft invoice')
                    ->modalDescription('The work is invoiced gross with retention as its own line against a retention asset — never net, which would understate revenue for the life of the job. It stops at draft: issuing an invoice transmits it, and that stays a decision somebody makes.')
                    // Absent without Invoicing (§18.1's guarded coupling) rather than present and failing: without
                    // it there is nothing for the certificate to become, and the certificate is still the whole
                    // deliverable.
                    ->visible(fn (PaymentCertificate $record): bool => app(CertificateInvoiceService::class)->canRaise()
                        && (auth()->user()?->can('invoice', $record) ?? false))
                    ->action(fn (PaymentCertificate $record) => static::run(
                        fn () => app(CertificateInvoiceService::class)->raise($record),
                        'Draft invoice raised.',
                        'Gross, with retention as an asset. Issue it from Invoicing when it has been checked.',
                    )),

                Action::make('void')
                    ->label('Void')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading('Void this certificate')
                    ->modalDescription('The number stays on the register — a gap in the series is a question at adjudication, and "voided on the 14th" is an answer. The next certificate absorbs the difference automatically.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->rows(3)
                            ->required()
                            ->helperText('Somebody outside this company has a copy of this certificate.'),
                    ])
                    ->visible(fn (PaymentCertificate $record): bool => auth()->user()?->can('void', $record) ?? false)
                    ->action(fn (PaymentCertificate $record, array $data) => static::run(
                        fn () => app(CertificationService::class)->void($record, $data['reason']),
                        'Voided.',
                        'The reason is on the record and the number is kept.',
                    )),
            ]);
    }

    /**
     * Service refusals are sentences somebody needs to read, so they are surfaced rather than thrown.
     *
     * `RuntimeException` is caught alongside `InvalidArgumentException` because the account-map refusals name the
     * settings page and the seeder — the most useful message in this whole file, and the one a stack trace would
     * bury.
     */
    private static function run(callable $call, string $title, string $body): void
    {
        try {
            $call();
        } catch (InvalidArgumentException|\RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($title)->body($body)->send();
    }
}
