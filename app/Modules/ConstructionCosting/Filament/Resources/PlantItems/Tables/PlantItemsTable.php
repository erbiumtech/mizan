<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\PlantItems\Tables;

use App\Modules\ConstructionCosting\Models\PlantItem;
use App\Modules\ConstructionCosting\Services\PlantHireMatch;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The fleet register, and §7.3's two-way match as an action on each hired row.
 *
 * **The match is an action rather than a column** because it reads every log and every allocation for a machine, and a
 * column doing that per row is the query-per-row trap §18.3 names. It is also a question somebody asks about one
 * machine when an invoice lands, not a figure they scan a list for.
 */
class PlantItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Plant no.')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('ownership')
                    ->label('Held')
                    ->badge()
                    ->color(fn (string $state): string => $state === PlantItem::OWNERSHIP_OWNED ? 'success' : 'info')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        PlantItem::OWNERSHIP_OWNED => 'Owned',
                        PlantItem::OWNERSHIP_HIRED => 'Hired',
                        default => 'Hired + operator',
                    })
                    ->sortable(),

                TextColumn::make('supplier.name')
                    ->label('From')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('working_rate')
                    ->label('Working rate')
                    ->money('PKR')
                    ->alignEnd()
                    // Not a dash: a machine with no rate cannot be approved against at all, and that is worth seeing
                    // on the register rather than discovering at approval.
                    ->placeholder('no rate set')
                    ->sortable(),

                TextColumn::make('idle_rate')
                    ->label('Idle')
                    ->money('PKR')
                    ->alignEnd()
                    ->placeholder('not charged')
                    ->toggleable(),

                TextColumn::make('standby_rate')
                    ->label('Standby')
                    ->money('PKR')
                    ->alignEnd()
                    ->placeholder('not charged')
                    ->toggleable(),

                TextColumn::make('commitment.number')
                    ->label('Hire order')
                    // The absence is the finding on a hired machine: without an order, nothing checks the invoice.
                    ->placeholder(fn (PlantItem $record): string => $record->isOwned() ? '—' : 'none linked')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('In fleet')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('ownership')
                    ->label('How it is held')
                    ->options(PlantItem::OWNERSHIPS),

                Filter::make('active')
                    ->label('In the fleet only')
                    ->query(fn (Builder $query) => $query->active())
                    ->default()
                    ->toggle(),

                // The exposure: a hired machine whose invoices nothing is checking.
                Filter::make('hired_without_order')
                    ->label('Hired, no order linked')
                    ->query(fn (Builder $query) => $query->hired()->whereNull('commitment_id'))
                    ->toggle(),

                Filter::make('no_rate')
                    ->label('No rate set')
                    ->query(fn (Builder $query) => $query
                        ->where(fn (Builder $q) => $q->whereNull('working_rate')->orWhere('working_rate', 0))
                        ->where(fn (Builder $q) => $q->whereNull('idle_rate')->orWhere('idle_rate', 0))
                        ->where(fn (Builder $q) => $q->whereNull('standby_rate')->orWhere('standby_rate', 0)))
                    ->toggle(),
            ])
            ->defaultSort('code')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (PlantItem $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('checkInvoices')
                    ->label('Check against invoices')
                    ->icon('heroicon-o-scale')
                    ->color('gray')
                    ->modalHeading('Days on site against what has been invoiced')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->visible(fn (PlantItem $record): bool => $record->isHired()
                        && (auth()->user()?->can('view', $record) ?? false))
                    ->modalDescription(fn (PlantItem $record): string => static::matchSummary($record))
                    ->action(fn () => null),
            ])
            ->emptyStateHeading('No plant registered')
            ->emptyStateDescription('Owned machines charge jobs internal hire from their logs. Hired machines are costed by their supplier invoice, and their logs are what checks it.');
    }

    /**
     * §7.3's comparison, in words.
     *
     * Written as a sentence rather than a table of figures because the useful output is the *interpretation* — "more
     * has been invoiced than the logs support" — and a reader who only sees two numbers has to work out which
     * direction is the bad one.
     */
    private static function matchSummary(PlantItem $item): string
    {
        $match = app(PlantHireMatch::class)->for($item);

        if ($match['status'] === PlantHireMatch::STATUS_NOT_APPLICABLE) {
            return $match['explanation'];
        }

        return 'Logged '.number_format($match['logged_units'], 2).' units, worth '
            .number_format($match['expected'], 2).'. Invoiced and allocated: '
            .number_format($match['invoiced'], 2).'. '
            .$match['explanation'];
    }
}
