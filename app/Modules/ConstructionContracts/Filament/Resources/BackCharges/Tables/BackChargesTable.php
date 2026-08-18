<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\BackCharges\Tables;

use App\Modules\ConstructionContracts\Models\BackCharge;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Services\BackChargeService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The back-charge register.
 *
 * **The un-notified filter is the reason this screen exists.** §12: an incurred-but-unnotified back-charge is money the
 * company will not get and does not yet know it has lost. The filter turns "does not yet know" into a list with a total
 * at the foot of it.
 *
 * **Applying is an action, never a consequence.** Following §16.5's rule for NCRs, a certificate never grows a
 * back-charge deduction by itself — somebody chooses the certificate and signs for the deduction.
 */
class BackChargesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Ref')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contract.contract_number')
                    ->label('Subcontract')
                    ->description(fn (BackCharge $record): ?string => $record->job?->code)
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('kind')
                    ->label('For')
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', $state))
                    ->toggleable(),

                TextColumn::make('description')
                    ->wrap()
                    ->limit(80)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        // Draft is the warning colour on purpose: it is the only state that cannot be recovered, so a
                        // quiet grey would make the exposure look like housekeeping.
                        BackCharge::STATUS_DRAFT => 'warning',
                        BackCharge::STATUS_NOTIFIED => 'info',
                        BackCharge::STATUS_DISPUTED => 'danger',
                        BackCharge::STATUS_AGREED => 'success',
                        BackCharge::STATUS_APPLIED => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('incurred_on')
                    ->label('Incurred')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('notified_on')
                    ->label('Notice served')
                    // Not a dash: the absence is the finding, and it is the sentence §12 is about.
                    ->placeholder('Not served')
                    ->date()
                    ->sortable(),

                TextColumn::make('total_amount')
                    ->label('Notified')
                    ->money('PKR')
                    ->alignEnd()
                    ->summarize(Sum::make()->money('PKR')->label('Total'))
                    ->sortable(),

                TextColumn::make('agreed_amount')
                    ->label('Settled')
                    ->money('PKR')
                    ->alignEnd()
                    // Beside the notified figure rather than over it: "notified 240,000, settled at 180,000" is the
                    // fact somebody needs at final account.
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('appliedCertificate.certificate_number')
                    ->label('Deducted on')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('withdrawal_reason')
                    ->label('Withdrawn because')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('contract_id')
                    ->label('Subcontract')
                    ->relationship('contract', 'contract_number')
                    ->searchable(),

                SelectFilter::make('status')
                    ->options([
                        BackCharge::STATUS_DRAFT => 'Draft — no notice served',
                        BackCharge::STATUS_NOTIFIED => 'Notified',
                        BackCharge::STATUS_DISPUTED => 'Disputed',
                        BackCharge::STATUS_AGREED => 'Agreed',
                        BackCharge::STATUS_APPLIED => 'Applied',
                        BackCharge::STATUS_WITHDRAWN => 'Withdrawn',
                    ]),

                // §12's exposure, as a toggle.
                Filter::make('unnotified')
                    ->label('No notice served')
                    ->query(fn (Builder $query) => $query->unnotified())
                    ->toggle(),

                Filter::make('awaiting_application')
                    ->label('Recoverable, not yet deducted')
                    ->query(fn (Builder $query) => $query->awaitingApplication())
                    ->toggle(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (BackCharge $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('notify')
                    ->label('Serve notice')
                    ->icon('heroicon-o-megaphone')
                    ->color('info')
                    ->modalHeading('Record the notice')
                    ->modalDescription('Almost every subcontract requires notice before a back-charge may be deducted. Until this date is on the row, nothing here can come off a payment.')
                    ->schema([
                        DatePicker::make('notified_on')
                            ->label('Notice served on')
                            ->default(now())
                            ->required()
                            // The subcontractor's date, not today's: notice served on the 3rd and recorded on the 11th
                            // is notice served on the 3rd.
                            ->helperText('The date the notice went to the subcontractor, not the date you are typing.'),
                    ])
                    ->visible(fn (BackCharge $record): bool => auth()->user()?->can('notify', $record) ?? false)
                    ->action(fn (BackCharge $record, array $data) => static::run(
                        fn () => app(BackChargeService::class)->notify($record, $data['notified_on'] ?? null),
                        'Notice recorded.',
                        'The charge may now be deducted from a draft certificate on this subcontract.',
                    )),

                Action::make('dispute')
                    ->label('Record dispute')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->modalHeading('Record the subcontractor\'s objection')
                    ->modalDescription('A disputed charge is still deductible under most subcontracts — the argument goes where the contract says arguments go. This records that it is contested.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Their grounds')
                            ->rows(3)
                            ->required(),
                    ])
                    ->visible(fn (BackCharge $record): bool => auth()->user()?->can('dispute', $record) ?? false)
                    ->action(fn (BackCharge $record, array $data) => static::run(
                        fn () => app(BackChargeService::class)->dispute($record, $data['reason']),
                        'Dispute recorded.',
                        'The charge stays recoverable; the grounds are on the row.',
                    )),

                Action::make('agree')
                    ->label('Agree')
                    ->icon('heroicon-o-scale')
                    ->color('success')
                    ->modalHeading('Settle the amount')
                    ->modalDescription('A settlement below the notified figure gives away part of a recovery the company was entitled to. Both figures stay on the row.')
                    ->schema([
                        TextInput::make('agreed_amount')
                            ->label('Agreed amount')
                            ->numeric()
                            ->default(fn (BackCharge $record): float => (float) $record->total_amount)
                            ->helperText('Leave at the notified figure where nothing was conceded. Above it is refused — that would be a new charge, needing its own notice.'),
                    ])
                    ->visible(fn (BackCharge $record): bool => auth()->user()?->can('agree', $record) ?? false)
                    ->action(fn (BackCharge $record, array $data) => static::run(
                        fn () => app(BackChargeService::class)->agree(
                            $record,
                            isset($data['agreed_amount']) ? (float) $data['agreed_amount'] : null,
                        ),
                        'Agreed.',
                        'The settled figure sits beside the notified one, so the concession stays visible.',
                    )),

                Action::make('apply')
                    ->label('Deduct on certificate')
                    ->icon('heroicon-o-minus-circle')
                    ->color('warning')
                    ->modalHeading('Deduct this from a payment')
                    ->modalDescription('Writes one back-charge line on a draft certificate, negative by the convention that a negative amount reduces the payment. Nothing does this automatically — a deduction nobody decided on is the fastest route to a dispute.')
                    ->schema([
                        Select::make('payment_certificate_id')
                            ->label('Draft certificate')
                            ->options(fn (BackCharge $record): array => PaymentCertificate::query()
                                ->where('contract_id', $record->contract_id)
                                ->where('status', PaymentCertificate::STATUS_DRAFT)
                                ->pluck('certificate_number', 'id')
                                ->all())
                            ->required()
                            ->helperText('Drafts on this subcontract only. An issued certificate is frozen; the charge belongs on the next one.'),
                    ])
                    ->visible(fn (BackCharge $record): bool => auth()->user()?->can('apply', $record) ?? false)
                    ->action(fn (BackCharge $record, array $data) => static::run(
                        fn () => app(BackChargeService::class)->apply(
                            $record,
                            PaymentCertificate::findOrFail($data['payment_certificate_id']),
                        ),
                        'Deducted.',
                        'The certificate carries a back-charge line traceable to this row.',
                    )),

                Action::make('unapply')
                    ->label('Take off certificate')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->modalHeading('Take this back off the certificate')
                    ->modalDescription('Removes the deduction line while the certificate is still a draft. The charge returns to notified or agreed — never to draft, because notice cannot be unserved.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->rows(2),
                    ])
                    ->visible(fn (BackCharge $record): bool => auth()->user()?->can('unapply', $record) ?? false)
                    ->action(fn (BackCharge $record, array $data) => static::run(
                        fn () => app(BackChargeService::class)->unapply($record, $data['reason'] ?? null),
                        'Taken off.',
                        'The certificate has been recomputed without it.',
                    )),

                Action::make('withdraw')
                    ->label('Withdraw')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading('Drop this charge')
                    ->modalDescription('Writes off the recovery. The row stays on the register — it is what explains why the final account does not add up to the notices.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->rows(3)
                            ->required()
                            ->helperText('The subcontractor was told about this charge. Write the sentence that answers "why not?".'),
                    ])
                    ->visible(fn (BackCharge $record): bool => auth()->user()?->can('withdraw', $record) ?? false)
                    ->action(fn (BackCharge $record, array $data) => static::run(
                        fn () => app(BackChargeService::class)->withdraw($record, $data['reason']),
                        'Withdrawn.',
                        'The reason is on the record.',
                    )),
            ]);
    }

    /** Service refusals are sentences somebody needs to read, so they are surfaced rather than thrown. */
    private static function run(callable $call, string $title, string $body): void
    {
        try {
            $call();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($title)->body($body)->send();
    }
}
