<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\RetentionMovements\Tables;

use App\Modules\ConstructionContracts\Models\RetentionMovement;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ledger as a table, with the balance as the column total.
 *
 * **The sum of the amount column *is* the money held**, which is why the summariser is on it and why the sign
 * convention matters on screen as much as in the code: positive held, negative released or forfeited. Filter to one
 * contract and the total is that contract's balance — no separate figure to maintain and none to go stale.
 *
 * `cap_reached` earns its column: a movement smaller than rate × basis is the question somebody asks once per job,
 * and "the limit of retention" is the answer.
 */
class RetentionMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Recorded')
                    ->date()
                    ->sortable(),

                TextColumn::make('contract.contract_number')
                    ->label('Contract')
                    ->description(fn (RetentionMovement $record): ?string => $record->contract?->job?->code)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('kind')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        RetentionMovement::KIND_HELD, RetentionMovement::KIND_REINSTATED => 'warning',
                        RetentionMovement::KIND_RELEASED => 'success',
                        RetentionMovement::KIND_FORFEITED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state))),

                TextColumn::make('stage')
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state)))
                    ->toggleable(),

                TextColumn::make('certificate.certificate_number')
                    ->label('Certificate')
                    // Releases often name no certificate (§11), and an em dash says so rather than implying a
                    // missing link.
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('amount')
                    ->money('PKR')
                    ->alignEnd()
                    ->color(fn (RetentionMovement $record): string => $record->isRelease() ? 'success' : 'warning')
                    ->sortable()
                    // Filter to one contract and this total is its balance.
                    ->summarize(Sum::make()->label('Balance')->money('PKR')),

                TextColumn::make('basis_gross')
                    ->label('On gross of')
                    ->money('PKR')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('rate_applied')
                    ->label('Rate')
                    ->suffix('%')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('cap_reached')
                    ->label('At cap')
                    ->boolean()
                    ->tooltip('The limit of retention was reached, which is why this movement is smaller than rate × basis.')
                    ->toggleable(),

                TextColumn::make('due_on')
                    ->label('Due')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('reason')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('contract_id')
                    ->label('Contract')
                    ->relationship('contract', 'contract_number')
                    ->searchable(),

                SelectFilter::make('kind')
                    ->options([
                        RetentionMovement::KIND_HELD => 'Held',
                        RetentionMovement::KIND_RELEASED => 'Released',
                        RetentionMovement::KIND_FORFEITED => 'Forfeited',
                        RetentionMovement::KIND_SUBSTITUTED_BY_BOND => 'Substituted by bond',
                        RetentionMovement::KIND_REINSTATED => 'Reinstated',
                        RetentionMovement::KIND_ADJUSTED => 'Adjusted',
                    ])
                    ->multiple(),

                // What is releasable now and has not been released: the list the notification run reads.
                Filter::make('due')
                    ->label('Due for release')
                    ->query(fn (Builder $query) => $query->due())
                    ->toggle(),
            ])
            ->defaultSort('id', 'desc')
            // No edit and no delete: a movement is an event, and the correction is another movement.
            ->recordActions([]);
    }
}
