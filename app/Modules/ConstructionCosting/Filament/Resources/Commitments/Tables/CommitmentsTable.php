<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Commitments\Tables;

use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Services\CommitmentService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The order register — `docs/construction-management-plan.md` §5.
 *
 * **Ordered, relieved and open, on every row.** Open is what a site manager manages by: money promised and not yet
 * received, certified or invoiced. It is computed from the reliefs rather than stored, so it cannot disagree with the
 * rows underneath it.
 *
 * **Approve and Issue are two buttons.** Approving says this company will spend the money; issuing tells the
 * supplier, and only then does the figure appear on the four-column report's committed column. An approved order in a
 * drawer can still be withdrawn with a phone call; an issued one cannot.
 *
 * **Close and Cancel are also two**, and the difference is worth the extra button: closing says "this is finished and
 * what is left will not be spent", cancelling says "this should never have been placed". Both write a relief so the
 * committed column moves, and both demand a reason.
 */
class CommitmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state)))
                    ->toggleable(),

                TextColumn::make('contact.name')
                    ->label('Supplier')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('description')
                    ->wrap()
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Commitment::STATUS_ISSUED, Commitment::STATUS_PARTIALLY_RELIEVED => 'success',
                        Commitment::STATUS_CANCELLED => 'danger',
                        Commitment::STATUS_CLOSED => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state)))
                    // The distinction the whole register turns on, said on the row rather than left to a badge
                    // colour: approved is a decision inside this company, issued is a promise outside it.
                    ->description(fn (Commitment $record): ?string => $record->status === Commitment::STATUS_APPROVED
                        ? 'approved, not yet issued — not committed'
                        : null),

                TextColumn::make('ordered')
                    ->label('Ordered')
                    ->money('PKR')
                    ->alignEnd()
                    ->state(fn (Commitment $record): float => $record->orderedTotal()),

                TextColumn::make('relieved')
                    ->label('Received / invoiced')
                    ->money('PKR')
                    ->alignEnd()
                    ->state(fn (Commitment $record): float => $record->relievedTotal())
                    ->toggleable(),

                TextColumn::make('open')
                    ->label('Open')
                    ->money('PKR')
                    ->alignEnd()
                    ->weight('bold')
                    ->state(fn (Commitment $record): float => $record->openTotal())
                    ->tooltip('Ordered less what has been received, certified, invoiced or written off. Computed from the reliefs.')
                    // More relieved than ordered is a real condition rather than an impossible one, and it is
                    // exactly what a heuristic committed figure leaves behind and cannot clear.
                    ->color(fn (Commitment $record): string => $record->overRelieved() ? 'danger' : 'gray')
                    ->description(fn (Commitment $record): ?string => $record->overRelieved()
                        ? 'over-relieved'
                        : null),

                TextColumn::make('required_by')
                    ->label('Required by')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('close_reason')
                    ->label('Closed because')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        Commitment::TYPE_PURCHASE_ORDER => 'Purchase order',
                        Commitment::TYPE_SUBCONTRACT => 'Subcontract order',
                        Commitment::TYPE_PLANT_HIRE => 'Plant hire',
                        Commitment::TYPE_MANUAL => 'Manual',
                    ])
                    ->multiple(),

                SelectFilter::make('status')
                    ->options([
                        Commitment::STATUS_DRAFT => 'Draft',
                        Commitment::STATUS_PENDING_APPROVAL => 'Pending approval',
                        Commitment::STATUS_APPROVED => 'Approved',
                        Commitment::STATUS_ISSUED => 'Issued',
                        Commitment::STATUS_PARTIALLY_RELIEVED => 'Partially relieved',
                        Commitment::STATUS_CLOSED => 'Closed',
                        Commitment::STATUS_CANCELLED => 'Cancelled',
                    ])
                    ->multiple(),

                // What is still promised: the list somebody works from when a job looks overspent.
                Filter::make('open')
                    ->label('Still open')
                    ->query(fn (Builder $query) => $query->open())
                    ->toggle(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Commitment $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve this order')
                    ->modalDescription('Says this company will spend the money. It is not committed until it is issued to the supplier, which is the next button.')
                    ->visible(fn (Commitment $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->action(fn (Commitment $record) => static::run(
                        fn () => app(CommitmentService::class)->approve($record),
                        'Approved.',
                        'Issue it to put the money on the committed column.',
                    )),

                Action::make('issue')
                    ->label('Issue')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Issue to the supplier')
                    ->modalDescription('From here the money is committed: it appears on the job cost report and reduces what the cost code has left, before any invoice arrives.')
                    ->visible(fn (Commitment $record): bool => auth()->user()?->can('issue', $record) ?? false)
                    ->action(fn (Commitment $record) => static::run(
                        fn () => app(CommitmentService::class)->issue($record),
                        'Issued.',
                        'The commitment is now on the cost report.',
                    )),

                Action::make('close')
                    ->label('Close')
                    ->icon('heroicon-o-archive-box')
                    ->modalHeading('Close this order')
                    ->modalDescription('Whatever is still open is written off and comes off the committed column. This is an intentional act with your name on it — an order that quietly stops changing is a commitment nobody will ever clear.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->rows(2)
                            ->required()
                            ->helperText('"Delivered short and agreed to leave it" reads very differently from "somebody forgot".'),
                    ])
                    ->visible(fn (Commitment $record): bool => auth()->user()?->can('close', $record) ?? false)
                    ->action(fn (Commitment $record, array $data) => static::run(
                        fn () => app(CommitmentService::class)->close($record, $data['reason']),
                        'Closed.',
                        'What was left is off the committed column, with the reason on the record.',
                    )),

                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading('Cancel this order')
                    ->modalDescription('Says it should never have been placed. Everything still open comes off the committed column.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->rows(2)
                            ->required(),
                    ])
                    ->visible(fn (Commitment $record): bool => auth()->user()?->can('close', $record) ?? false)
                    ->action(fn (Commitment $record, array $data) => static::run(
                        fn () => app(CommitmentService::class)->cancel($record, $data['reason']),
                        'Cancelled.',
                        'The commitment is off the cost report.',
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
