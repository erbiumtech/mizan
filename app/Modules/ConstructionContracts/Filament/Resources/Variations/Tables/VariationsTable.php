<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\Variations\Tables;

use App\Modules\ConstructionContracts\Models\Variation;
use App\Modules\ConstructionContracts\Services\VariationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The variation register, and the state machine as five buttons.
 *
 * **Approve and Approve in principle are two buttons because they are two decisions**, and the difference is the
 * whole of §9: approving agrees the price and lets a certificate include it; approving in principle says the work
 * is instructed and proceeding while the price is still argued, so the forecast carries it and no certificate
 * does. A single "approve" with a provisional checkbox would be one careless tick away from certifying money
 * nobody agreed.
 *
 * The **Provisional** filter is the report a commercial manager actually wants: everything the job is spending
 * that nobody has agreed yet.
 */
class VariationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('variation_number')
                    ->label('Number')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contract.contract_number')
                    ->label('Contract')
                    ->description(fn (Variation $record): ?string => $record->contract?->job?->code)
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('title')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('origin')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state)))
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Variation::STATUS_APPROVED, Variation::STATUS_INCORPORATED => 'success',
                        Variation::STATUS_APPROVED_IN_PRINCIPLE => 'warning',
                        Variation::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state)))
                    // The flag that decides whether the money is certified or merely forecast, on the row.
                    ->description(fn (Variation $record): ?string => $record->is_price_provisional
                        ? 'price provisional — forecast, not certified'
                        : null),

                TextColumn::make('quoted_amount')
                    ->label('Quoted')
                    ->money('PKR')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('assessed_amount')
                    ->label('Assessed')
                    ->money('PKR')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('approved_amount')
                    ->label('Approved')
                    ->money('PKR')
                    ->alignEnd()
                    // Null rather than zero until it is agreed: zero would read as an agreed variation worth
                    // nothing, which is a different fact.
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('time_granted_days')
                    ->label('EOT days')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('approved_on')
                    ->label('Approved')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('contract_id')
                    ->label('Contract')
                    ->relationship('contract', 'contract_number')
                    ->searchable(),

                SelectFilter::make('status')
                    ->options([
                        Variation::STATUS_DRAFT => 'Draft',
                        Variation::STATUS_SUBMITTED => 'Submitted',
                        Variation::STATUS_PRICED => 'Priced',
                        Variation::STATUS_APPROVED_IN_PRINCIPLE => 'Approved in principle',
                        Variation::STATUS_APPROVED => 'Approved',
                        Variation::STATUS_REJECTED => 'Rejected',
                        Variation::STATUS_INCORPORATED => 'Incorporated',
                    ])
                    ->multiple(),

                // The list somebody chases: submitted or priced and waiting on a decision.
                Filter::make('outstanding')
                    ->label('Awaiting a decision')
                    ->query(fn (Builder $query) => $query->outstanding())
                    ->toggle(),

                // Money the job is spending that nobody has agreed.
                Filter::make('provisional')
                    ->label('Price provisional')
                    ->query(fn (Builder $query) => $query->where('is_price_provisional', true))
                    ->toggle(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Variation $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('submit')
                    ->label('Submit')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (Variation $record): bool => $record->status === Variation::STATUS_DRAFT
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(fn (Variation $record) => static::run(
                        fn () => app(VariationService::class)->submit($record),
                        'Submitted.',
                        'Time bars run from the submission date.',
                    )),

                Action::make('price')
                    ->label('Price')
                    ->icon('heroicon-o-calculator')
                    ->modalHeading('Assess this variation')
                    ->modalDescription('The assessed amount defaults to the sum of the priced lines. A certifier\'s own figure differing from it is exactly what the two columns are for.')
                    ->schema([
                        TextInput::make('assessed')
                            ->label('Assessed amount')
                            ->numeric()
                            ->helperText('Left blank, the sum of the lines.'),
                    ])
                    ->visible(fn (Variation $record): bool => auth()->user()?->can('price', $record) ?? false)
                    ->action(fn (Variation $record, array $data) => static::run(
                        fn () => app(VariationService::class)->price(
                            $record,
                            ($data['assessed'] ?? null) === null ? null : (float) $data['assessed'],
                        ),
                        'Priced.',
                        'An amount is on the table. Approving it is a separate decision.',
                    )),

                Action::make('approveInPrinciple')
                    ->label('Approve in principle')
                    ->icon('heroicon-o-clock')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Approve in principle, price still provisional')
                    ->modalDescription('Says the work is instructed and proceeding while the price is argued. The cost report will carry it; no certificate will. This is the state most variations live in for months.')
                    ->schema([
                        Select::make('confidence')
                            ->label('How firm is the figure')
                            ->options(['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'])
                            ->default('medium')
                            ->selectablePlaceholder(false)
                            ->helperText('Shown to whoever reads the forecast.'),
                    ])
                    ->visible(fn (Variation $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->action(fn (Variation $record, array $data) => static::run(
                        fn () => app(VariationService::class)->approveInPrinciple($record, $data['confidence'] ?? 'medium'),
                        'Approved in principle.',
                        'Forecast, not certified — the price is still provisional.',
                    )),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve the agreed amount')
                    ->modalDescription('The price is agreed. It joins the certified contract sum and a certificate may include it.')
                    ->schema([
                        TextInput::make('amount')
                            ->label('Agreed amount')
                            ->numeric()
                            ->helperText('Left blank, the assessed amount stands.'),
                    ])
                    ->visible(fn (Variation $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->action(fn (Variation $record, array $data) => static::run(
                        fn () => app(VariationService::class)->approve(
                            $record,
                            ($data['amount'] ?? null) === null ? null : (float) $data['amount'],
                        ),
                        'Approved.',
                        'It is now part of the certified contract sum.',
                    )),

                Action::make('incorporate')
                    ->label('Write into schedule')
                    ->icon('heroicon-o-document-plus')
                    ->requiresConfirmation()
                    ->modalHeading('Write this variation into the schedule')
                    ->modalDescription('Additions become new schedule lines and omissions become negative ones — never a reduction of the line they omit, which would break certificates already issued.')
                    ->visible(fn (Variation $record): bool => auth()->user()?->can('incorporate', $record) ?? false)
                    ->action(fn (Variation $record) => static::run(
                        fn () => app(VariationService::class)->incorporate($record),
                        'Written into the schedule.',
                        'The schedule now reflects what the parties agreed.',
                    )),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading('Reject this variation')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->rows(3)
                            ->required()
                            ->helperText('Read months later, by a lawyer.'),
                    ])
                    ->visible(fn (Variation $record): bool => (auth()->user()?->can('approve', $record) ?? false)
                        && $record->status !== Variation::STATUS_REJECTED)
                    ->action(fn (Variation $record, array $data) => static::run(
                        fn () => app(VariationService::class)->reject($record, $data['reason']),
                        'Rejected.',
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
