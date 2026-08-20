<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\Tables;

use App\Modules\ConstructionCosting\Models\MaterialIssue;
use App\Modules\ConstructionCosting\Services\MaterialIssueService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use RuntimeException;

/**
 * The issue register.
 *
 * **The value column says what moved, not what was added.** Posting a docket reclassifies cost from the code the
 * material was received at to the code it was used on; the job's total is unchanged. Labelling this "cost" would invite
 * exactly the reading the design is built to prevent.
 */
class MaterialIssuesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Docket')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('issued_on')
                    ->label('Issued')
                    ->date()
                    ->sortable(),

                TextColumn::make('store.code')
                    ->label('Out of')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('lines_count')
                    ->label('Lines')
                    ->counts('lines')
                    ->alignEnd()
                    ->toggleable(),

                // Not "cost": posting moves cost between codes and adds none, and a column called cost would be read
                // as an addition to the job.
                //
                // **No summariser**, and this is the second time in this suite: `Sum` needs a real column, and this
                // state is folded over the lines — so it goes looking for `construction_material_issues.value` and the
                // query fails outright. Phase 6c's `OrdersRelationManager` records the same finding. The total belongs
                // on the lines, where `amount` is a column, and it is there.
                TextColumn::make('value')
                    ->label('Value moved')
                    ->money('PKR')
                    ->alignEnd()
                    ->getStateUsing(fn (MaterialIssue $record): float => $record->totalValue())
                    ->description('reclassified, not added'),

                TextColumn::make('received_by')
                    ->label('Received by')
                    ->getStateUsing(fn (MaterialIssue $record): ?string => $record->receivedBy())
                    ->placeholder('unsigned')
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        MaterialIssue::STATUS_POSTED => 'success',
                        MaterialIssue::STATUS_REVERSED => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('stock_location_id')
                    ->label('Store')
                    ->relationship('store', 'name')
                    ->searchable(),

                SelectFilter::make('status')
                    ->options([
                        MaterialIssue::STATUS_DRAFT => 'Draft',
                        MaterialIssue::STATUS_POSTED => 'Posted',
                        MaterialIssue::STATUS_REVERSED => 'Reversed',
                    ]),

                // A draft docket is material that has physically gone with the stock still showing it in the store.
                Filter::make('unposted')
                    ->label('Not posted — stock still shows it')
                    ->query(fn (Builder $query) => $query->draft())
                    ->toggle(),
            ])
            ->defaultSort('issued_on', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (MaterialIssue $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('post')
                    ->label('Post')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Take this material off the store')
                    ->modalDescription('The stock leaves at FIFO cost, wastage goes out as its own waste movement, and the cost moves from the code the material was received at to the code it was used on. The job\'s total cost does not change — it was costed when it was delivered.')
                    ->visible(fn (MaterialIssue $record): bool => auth()->user()?->can('post', $record) ?? false)
                    ->action(fn (MaterialIssue $record) => static::run(
                        fn () => app(MaterialIssueService::class)->post($record),
                        'Posted.',
                        'The store is lighter and the cost sits on the code the material was used on.',
                    )),

                Action::make('reverse')
                    ->label('Reverse')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->modalHeading('Put this docket back')
                    ->modalDescription('The material goes back on the store at the cost it left at, and the reclassification is unwound. The rows stay — somebody signed the paper.')
                    ->schema([
                        Textarea::make('reason')->label('Why')->rows(2)->required(),
                    ])
                    ->visible(fn (MaterialIssue $record): bool => auth()->user()?->can('reverse', $record) ?? false)
                    ->action(fn (MaterialIssue $record, array $data) => static::run(
                        fn () => app(MaterialIssueService::class)->reverse($record, $data['reason']),
                        'Reversed.',
                        'The material is back on the store and the cost is back where it was.',
                    )),

                DeleteAction::make()
                    ->visible(fn (MaterialIssue $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->emptyStateHeading('No material issued')
            ->emptyStateDescription('A docket takes material out of a site store to the work face. It moves cost between codes rather than adding any — the material was costed when it was delivered.');
    }

    /** Service refusals are sentences somebody needs to read, so they are surfaced rather than thrown. */
    private static function run(callable $call, string $title, string $body): void
    {
        try {
            $call();
        } catch (InvalidArgumentException|RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            return;
        }

        Notification::make()->success()->title($title)->body($body)->send();
    }
}
