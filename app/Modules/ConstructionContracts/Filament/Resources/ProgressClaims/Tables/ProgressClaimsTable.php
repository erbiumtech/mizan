<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\Tables;

use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Models\ProgressClaim;
use App\Modules\ConstructionContracts\Services\CertificationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The claim register, with **applied versus certified** on every row.
 *
 * That column is the reason two tables exist. It is the first thing a commercial manager looks for and it cannot
 * be computed at all if the certified figure overwrites the applied one.
 */
class ProgressClaimsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('claim_number')
                    ->label('Number')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contract.contract_number')
                    ->label('Contract')
                    ->description(fn (ProgressClaim $record): ?string => $record->contract?->job?->code)
                    ->toggleable(),

                TextColumn::make('period_end')
                    ->label('Valued to')
                    ->date()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ProgressClaim::STATUS_CERTIFIED => 'success',
                        ProgressClaim::STATUS_REJECTED => 'danger',
                        ProgressClaim::STATUS_SUPERSEDED => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state))),

                TextColumn::make('claimed_gross_to_date')
                    ->label('Applied')
                    ->money('PKR')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('certified')
                    ->label('Certified')
                    ->money('PKR')
                    ->alignEnd()
                    // Null rather than zero until a certificate exists: zero would read as "certified nothing",
                    // which is a different and much worse fact than "not certified yet".
                    ->placeholder('Not certified')
                    ->state(fn (ProgressClaim $record): ?float => $record->certificate?->gross_value_to_date === null
                        ? null
                        : (float) $record->certificate->gross_value_to_date),

                TextColumn::make('difference')
                    ->label('Difference')
                    ->money('PKR')
                    ->alignEnd()
                    ->placeholder('—')
                    ->color('danger')
                    ->state(function (ProgressClaim $record): ?float {
                        $certified = $record->certificate?->gross_value_to_date;

                        return $certified === null
                            ? null
                            : round((float) $certified - (float) $record->claimed_gross_to_date, 2);
                    })
                    ->tooltip('What the certifier disallowed. The question every commercial meeting starts with.'),
            ])
            ->filters([
                SelectFilter::make('contract_id')
                    ->label('Contract')
                    ->relationship('contract', 'contract_number')
                    ->searchable(),

                SelectFilter::make('status')
                    ->options([
                        ProgressClaim::STATUS_DRAFT => 'Draft',
                        ProgressClaim::STATUS_SUBMITTED => 'Submitted',
                        ProgressClaim::STATUS_UNDER_REVIEW => 'Under review',
                        ProgressClaim::STATUS_CERTIFIED => 'Certified',
                        ProgressClaim::STATUS_REJECTED => 'Rejected',
                    ]),

                Filter::make('awaiting')
                    ->label('Awaiting certification')
                    ->query(fn (Builder $query) => $query->awaitingCertification())
                    ->toggle(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (ProgressClaim $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('submit')
                    ->label('Submit')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->modalHeading('Submit this claim')
                    ->modalDescription('Every line\'s percent or quantity is resolved into a value now, and the header is stamped from the lines. Time bars run from the submission date.')
                    ->visible(fn (ProgressClaim $record): bool => $record->status === ProgressClaim::STATUS_DRAFT
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(fn (ProgressClaim $record) => static::run(
                        fn () => app(CertificationService::class)->submitClaim($record),
                        'Submitted.',
                        'The claimed figures are stamped from the lines.',
                    )),

                Action::make('certify')
                    ->label('Prepare certificate')
                    ->icon('heroicon-o-document-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Prepare a certificate from this claim')
                    ->modalDescription('A draft certificate, with the claim\'s figures as the starting point and the previous certificate\'s as the frozen comparison. Nothing is issued until somebody certifies it.')
                    ->visible(fn (ProgressClaim $record): bool => $record->status === ProgressClaim::STATUS_SUBMITTED
                        && $record->certificate === null
                        && (auth()->user()?->can('create', PaymentCertificate::class) ?? false))
                    ->action(fn (ProgressClaim $record) => static::run(
                        fn () => app(CertificationService::class)->prepare(
                            $record->contract,
                            $record->period_end->toDateString(),
                            $record,
                        ),
                        'Draft certificate prepared.',
                        'Check the deductions, then certify it.',
                    )),
            ]);
    }

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
