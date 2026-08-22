<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ComplianceRequirements\Tables;

use App\Modules\ConstructionContracts\Models\ComplianceRequirement;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The requirements register.
 *
 * Templates first, because that is the list somebody sets up once and then reads to check nothing is missing. A
 * contract-specific requirement is an exception, and exceptions read better underneath the rule they change.
 */
class ComplianceRequirementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')
                    ->label('Required')
                    ->formatStateUsing(fn (ComplianceRequirement $record): string => $record->label())
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contract.contract_number')
                    ->label('Contract')
                    // The template is a scope, not a missing contract.
                    ->placeholder('Company template')
                    ->description(fn (ComplianceRequirement $record): ?string => $record->contract?->job?->code)
                    ->sortable(),

                TextColumn::make('blocks')
                    ->label('Stops')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        ComplianceRequirement::BLOCKS_NONE => 'nothing',
                        ComplianceRequirement::BLOCKS_CERTIFICATION => 'certification',
                        ComplianceRequirement::BLOCKS_PAYMENT => 'payment',
                        default => 'both',
                    })
                    ->color(fn (string $state): string => $state === ComplianceRequirement::BLOCKS_NONE
                        ? 'gray'
                        : 'warning'),

                TextColumn::make('grace_days')
                    ->label('Grace')
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'none' : $state.' days')
                    ->alignEnd(),

                TextColumn::make('minimum_cover')
                    ->label('Minimum cover')
                    ->money('PKR')
                    ->alignEnd()
                    ->placeholder('any'),

                TextColumn::make('notes')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('template')
                    ->label('Company template only')
                    ->query(fn (Builder $query) => $query->template())
                    ->toggle(),

                SelectFilter::make('blocks')
                    ->options([
                        ComplianceRequirement::BLOCKS_NONE => 'Nothing',
                        ComplianceRequirement::BLOCKS_CERTIFICATION => 'Certification',
                        ComplianceRequirement::BLOCKS_PAYMENT => 'Payment',
                        ComplianceRequirement::BLOCKS_BOTH => 'Both',
                    ]),
            ])
            // Templates first, then the contracts that override them.
            ->defaultSort('contract_id')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (ComplianceRequirement $record): bool => auth()->user()?->can('update', $record) ?? false),

                /*
                 * Deletable, unlike a document. A requirement is a rule the company sets, and a rule it has stopped
                 * applying is not evidence of anything — where a lapsed policy is evidence that it lapsed.
                 */
                DeleteAction::make()
                    ->visible(fn (ComplianceRequirement $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ]);
    }
}
