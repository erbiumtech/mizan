<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Requisitions\Tables;

use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\Requisition;
use App\Modules\ConstructionCosting\Models\RequisitionLine;
use App\Modules\ConstructionCosting\Services\CommitmentService;
use App\Modules\ConstructionCosting\Services\RequisitionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The requisition register, which is two screens in one.
 *
 * For site it is "what I asked for and where it got to". For the buyer it is a **work queue**: the *To order* filter
 * shows approved requests with something still outstanding, soonest needed first — which is the list §5 says has no
 * home otherwise, because without a demand document the first record of a need is the order raised to satisfy it.
 *
 * **Order** raises order lines into a draft commitment and links them back, so a request part-ordered stays visible
 * with the rest outstanding rather than disappearing at the first order.
 */
class RequisitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('job.code')
                    ->label('Job')
                    ->description(fn (Requisition $record): ?string => $record->job?->name)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('required_by')
                    ->label('Needed by')
                    ->date()
                    // No date is not "no hurry" — it is a request nobody has dated, which the buyer has to chase.
                    ->placeholder('Not stated')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Requisition::STATUS_APPROVED => 'success',
                        Requisition::STATUS_ORDERED => 'gray',
                        Requisition::STATUS_PARTIALLY_ORDERED => 'info',
                        Requisition::STATUS_REJECTED, Requisition::STATUS_CANCELLED => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state))),

                TextColumn::make('lines_count')
                    ->label('Lines')
                    ->counts('lines')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('outstanding')
                    ->label('Still to order')
                    ->alignEnd()
                    // The count of lines with something outstanding rather than a quantity: quantities across
                    // different units do not add up, and "three lines still to order" is what a buyer acts on.
                    ->state(fn (Requisition $record): string => (string) $record->lines
                        ->filter(fn (RequisitionLine $line): bool => $line->outstandingQuantity() > 0.0)
                        ->count())
                    ->tooltip('Lines with something still to order. Quantities in different units do not add up, so this is a count.'),

                TextColumn::make('estimated')
                    ->label('Estimate')
                    ->money('PKR')
                    ->alignEnd()
                    ->state(fn (Requisition $record): float => $record->estimatedTotal())
                    ->tooltip('What site thinks it will cost. Nothing is committed until an order is issued.')
                    ->toggleable(),

                TextColumn::make('rejection_reason')
                    ->label('Because')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('job_id')
                    ->label('Job')
                    ->relationship('job', 'code')
                    ->searchable(),

                SelectFilter::make('status')
                    ->options([
                        Requisition::STATUS_DRAFT => 'Draft',
                        Requisition::STATUS_SUBMITTED => 'Submitted',
                        Requisition::STATUS_APPROVED => 'Approved',
                        Requisition::STATUS_PARTIALLY_ORDERED => 'Partially ordered',
                        Requisition::STATUS_ORDERED => 'Ordered',
                        Requisition::STATUS_REJECTED => 'Rejected',
                        Requisition::STATUS_CANCELLED => 'Cancelled',
                    ])
                    ->multiple(),

                // The buyer's queue. §5: without the demand document this list has no home at all.
                Filter::make('to_order')
                    ->label('To order')
                    ->query(fn (Builder $query) => $query->toOrder())
                    ->toggle(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Requisition $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('submit')
                    ->label('Submit')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (Requisition $record): bool => $record->isEditable()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(fn (Requisition $record) => static::run(
                        fn () => app(RequisitionService::class)->submit($record),
                        'Submitted.',
                        'It is on the approver\'s list. Nothing is committed by asking.',
                    )),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve this request')
                    ->modalDescription('Says the need is real and may be ordered. It commits nothing — the money is committed when the order that follows is issued to a supplier.')
                    ->visible(fn (Requisition $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->action(fn (Requisition $record) => static::run(
                        fn () => app(RequisitionService::class)->approve($record),
                        'Approved.',
                        'It is on the buyer\'s queue.',
                    )),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->modalHeading('Reject this request')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->rows(2)
                            ->required()
                            ->helperText('Site asked for something and is entitled to know why the answer is no. It stays editable, so "not like that, like this" works.'),
                    ])
                    ->visible(fn (Requisition $record): bool => (auth()->user()?->can('approve', $record) ?? false)
                        && ! $record->isClosed())
                    ->action(fn (Requisition $record, array $data) => static::run(
                        fn () => app(RequisitionService::class)->reject($record, $data['reason']),
                        'Rejected.',
                        'The reason is on the record and site can amend it.',
                    )),

                Action::make('order')
                    ->label('Order')
                    ->icon('heroicon-o-shopping-cart')
                    ->color('success')
                    ->modalHeading('Raise an order from this request')
                    ->modalDescription('Order lines are added to a draft order and linked back, so whatever is not ordered stays outstanding here. Nothing is committed until the order is issued.')
                    ->schema([
                        Select::make('commitment_id')
                            ->label('Add to order')
                            ->options(fn (): array => Commitment::query()
                                ->where('status', Commitment::STATUS_DRAFT)
                                ->get()
                                ->mapWithKeys(fn (Commitment $c): array => [$c->getKey() => $c->displayName()])
                                ->all())
                            ->searchable()
                            ->placeholder('A new draft order')
                            ->helperText('Left blank, a new draft order is raised. Several requests can go on one order to one supplier.'),
                    ])
                    // The buyer's grant, not the approver's: raising the order is the commitment side of the chain.
                    ->visible(fn (Requisition $record): bool => $record->isOrderable()
                        && (auth()->user()?->can('create', Commitment::class) ?? false))
                    ->action(function (Requisition $record, array $data): void {
                        static::run(function () use ($record, $data): void {
                            $commitments = app(CommitmentService::class);

                            $commitment = ($data['commitment_id'] ?? null)
                                ? Commitment::query()->findOrFail($data['commitment_id'])
                                : $commitments->create(['description' => "From {$record->number}"]);

                            app(RequisitionService::class)->order($record, $commitment);
                        }, 'Ordered.', 'The order is a draft: approve and issue it to commit the money.');
                    }),
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
