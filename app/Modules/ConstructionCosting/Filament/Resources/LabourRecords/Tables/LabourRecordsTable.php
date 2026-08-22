<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\Tables;

use App\Modules\ConstructionCosting\Models\LabourRecord;
use App\Modules\ConstructionCosting\Services\LabourRecordService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The site-sheet register, and the approval queue.
 *
 * **Hours are shown and minutes are stored**, which §7.1 asks for from both directions: the column is minutes so a
 * month of rates does not drift, and the screen prints hours because that is what a site sheet is discussed in.
 *
 * The **Awaiting approval** filter is the screen's job. A draft sheet is time somebody worked that the job is not yet
 * carrying, so a queue nobody clears is a job under-costed for as long as it sits there — the same shape as §5's
 * unallocated-invoice queue.
 */
class LabourRecordsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('worked_on')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('worker.name')
                    ->label('Worker')
                    ->description(fn (LabourRecord $record): ?string => $record->worker?->code)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('job.code')
                    ->label('Job')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('costCode.code')
                    ->label('Code')
                    ->toggleable(),

                TextColumn::make('trade.code')
                    ->label('As')
                    ->placeholder('usual trade')
                    ->toggleable(),

                TextColumn::make('hours')
                    ->label('Hours')
                    ->alignEnd()
                    ->getStateUsing(fn (LabourRecord $record): float => $record->hours())
                    ->description(fn (LabourRecord $record): ?string => $record->overtime_minutes > 0
                        ? round($record->overtime_minutes / 60, 2).' of it overtime'
                        : null),

                TextColumn::make('cost_rate_per_hour')
                    ->label('Rate')
                    ->money('PKR')
                    ->alignEnd()
                    // Not a dash: a draft has no rate *yet*, which is a different fact from a rate of nothing.
                    ->placeholder('at approval')
                    ->toggleable(),

                TextColumn::make('labour_amount')
                    ->label('Labour')
                    ->money('PKR')
                    ->alignEnd()
                    ->placeholder('—')
                    ->summarize(Sum::make()->money('PKR')->label('Labour')),

                TextColumn::make('burden_amount')
                    ->label('Burden')
                    ->money('PKR')
                    ->alignEnd()
                    // §7.3's separability, on a screen: labour and burden are two figures and stay two figures.
                    ->placeholder('—')
                    ->summarize(Sum::make()->money('PKR')->label('Burden'))
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        LabourRecord::STATUS_APPROVED => 'success',
                        LabourRecord::STATUS_REVERSED => 'danger',
                        // Warning rather than grey: a draft is worked time the job is not carrying yet.
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),

                TextColumn::make('reversal_reason')
                    ->label('Reversed because')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('job_id')
                    ->label('Job')
                    ->relationship('job', 'code')
                    ->searchable(),

                SelectFilter::make('worker_id')
                    ->label('Worker')
                    ->relationship('worker', 'name')
                    ->searchable(),

                SelectFilter::make('status')
                    ->options([
                        LabourRecord::STATUS_DRAFT => 'Draft',
                        LabourRecord::STATUS_APPROVED => 'Approved',
                        LabourRecord::STATUS_REVERSED => 'Reversed',
                    ]),

                // The queue. Time worked that the job is not carrying yet.
                Filter::make('awaiting_approval')
                    ->label('Awaiting approval')
                    ->query(fn (Builder $query) => $query->draft())
                    ->toggle(),
            ])
            ->defaultSort('worked_on', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (LabourRecord $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Book this day as cost')
                    ->modalDescription('The rate that applied on the day worked is frozen onto this record, and two cost entries are written — labour, and burden as its own entry against the same code. After this, a correction is a reversal.')
                    ->visible(fn (LabourRecord $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->action(fn (LabourRecord $record) => static::run(
                        fn () => app(LabourRecordService::class)->approve($record),
                        'Approved.',
                        'The job now carries the labour and its burden, at the rate that applied on the day.',
                    )),

                Action::make('reverse')
                    ->label('Reverse')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->modalHeading('Take this day back off the job')
                    ->modalDescription('Both entries are reversed as a pair — reversing the labour and leaving the burden would leave the job carrying burden on work it is no longer charged for. The rows stay on the ledger, which is what explains the pair.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->rows(2)
                            ->required()
                            ->helperText('The cost report will show both rows. This is the only thing that explains them.'),
                    ])
                    ->visible(fn (LabourRecord $record): bool => auth()->user()?->can('reverse', $record) ?? false)
                    ->action(fn (LabourRecord $record, array $data) => static::run(
                        fn () => app(LabourRecordService::class)->reverse($record, $data['reason']),
                        'Reversed.',
                        'Both the labour and the burden have been backed out, and the reason is on the record.',
                    )),

                DeleteAction::make()
                    // Drafts only: approved labour has cost behind it, and §3.3's rule is that a correction is a
                    // further row rather than a disappearance.
                    ->visible(fn (LabourRecord $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->emptyStateHeading('No site sheets')
            ->emptyStateDescription('A sheet is one person, one day, one cost code. Approving it is what puts the labour and its burden on the job.');
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
