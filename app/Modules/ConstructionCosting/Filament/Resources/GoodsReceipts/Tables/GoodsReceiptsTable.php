<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\Tables;

use App\Modules\ConstructionCosting\Models\GoodsReceipt;
use App\Modules\ConstructionCosting\Services\GoodsReceiptService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;
use RuntimeException;

/**
 * The delivery register.
 *
 * **Post is where the two things happen**: the order is relieved and accrued cost lands on the job. Until then a
 * receipt is somebody's typing, which is why it is a separate button rather than a consequence of saving.
 *
 * **Reverse rather than delete.** A posted receipt has moved the committed figure and the cost report; the correction
 * has to be a row somebody can read, which is the same discipline the cost ledger keeps.
 */
class GoodsReceiptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('received_on')
                    ->label('Received')
                    ->date()
                    ->sortable(),

                TextColumn::make('commitment.number')
                    ->label('Order')
                    // "Unordered" rather than an em dash: it is a fact about the delivery, not a missing field.
                    ->placeholder('Unordered')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('delivery_note_reference')
                    ->label('Delivery note')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        GoodsReceipt::STATUS_POSTED => 'success',
                        GoodsReceipt::STATUS_REVERSED => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('total')
                    ->label('Value')
                    ->money('PKR')
                    ->alignEnd()
                    ->state(fn (GoodsReceipt $record): float => $record->total())
                    ->tooltip('At order rate. The invoice may disagree, and the three-way match is where that is somebody\'s decision.'),

                TextColumn::make('lines_count')
                    ->label('Lines')
                    ->counts('lines')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('reversal_reason')
                    ->label('Reversed because')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        GoodsReceipt::STATUS_DRAFT => 'Draft',
                        GoodsReceipt::STATUS_POSTED => 'Posted',
                        GoodsReceipt::STATUS_REVERSED => 'Reversed',
                    ]),

                SelectFilter::make('commitment_id')
                    ->label('Order')
                    ->relationship('commitment', 'number')
                    ->searchable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (GoodsReceipt $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('post')
                    ->label('Post')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Post this delivery')
                    ->modalDescription('Relieves the order and puts accrued cost on the job at order rate. The accrual is what stops the month end understating everything delivered and not yet invoiced.')
                    ->visible(fn (GoodsReceipt $record): bool => auth()->user()?->can('post', $record) ?? false)
                    ->action(fn (GoodsReceipt $record) => static::run(
                        fn () => app(GoodsReceiptService::class)->post($record),
                        'Posted.',
                        'The order is relieved and the cost is on the job.',
                    )),

                Action::make('reverse')
                    ->label('Reverse')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->modalHeading('Reverse this delivery')
                    ->modalDescription('Puts the commitment back and reverses the accrued cost. Both stay on the record as rows — this is a correction, not a deletion.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->rows(2)
                            ->required()
                            ->helperText('It takes cost off a job and commitment back onto an order. Somebody will ask why.'),
                    ])
                    ->visible(fn (GoodsReceipt $record): bool => auth()->user()?->can('reverse', $record) ?? false)
                    ->action(fn (GoodsReceipt $record, array $data) => static::run(
                        fn () => app(GoodsReceiptService::class)->reverse($record, $data['reason']),
                        'Reversed.',
                        'The commitment is back on the order and the accrual is reversed.',
                    )),
            ]);
    }

    /**
     * Both exception types are caught: the store-destination refusal is a `RuntimeException` because it is about a
     * missing capability rather than bad input, and it is the most useful message on this screen.
     */
    private static function run(callable $call, string $title, string $body): void
    {
        try {
            $call();
        } catch (InvalidArgumentException|RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($title)->body($body)->send();
    }
}
