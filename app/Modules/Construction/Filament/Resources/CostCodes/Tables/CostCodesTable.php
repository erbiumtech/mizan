<?php

namespace App\Modules\Construction\Filament\Resources\CostCodes\Tables;

use App\Modules\Construction\Models\CostCode;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The library, ordered by code so it reads as the tree it is.
 *
 * The mapping columns are hidden by default and toggleable rather than absent: a UK contractor maintains NRM
 * and Uniclass and will never fill in MasterFormat, so showing all five to everybody makes the screen look
 * permanently half-finished. The **unmapped** filters are what make the gaps findable instead.
 */
class CostCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable()
                    // Indented by depth, so the tree is legible without a tree widget.
                    ->formatStateUsing(fn (string $state, CostCode $record): string => str_repeat('  ', $record->depth()).$state),

                TextColumn::make('name')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('cost_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CostCode::TYPE_LABOUR => 'info',
                        CostCode::TYPE_MATERIAL => 'success',
                        CostCode::TYPE_PLANT => 'warning',
                        CostCode::TYPE_SUBCONTRACT => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('unit')
                    ->label('Per')
                    ->placeholder('—')
                    ->toggleable(),

                // Whether anything may be booked against it. A heading with children cannot be, and this is
                // the column that explains why it is missing from a budget picker.
                IconColumn::make('is_leaf')
                    ->label('Bookable')
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('icms_category')
                    ->label('ICMS')
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? $state.' — '.(CostCode::ICMS_CATEGORIES[$state] ?? '?')
                        : '—')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('icms_group')
                    ->label('ICMS group')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('masterformat_code')->label('MasterFormat')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('uniformat_code')->label('UniFormat')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('uniclass_code')->label('Uniclass')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('omniclass_code')->label('OmniClass')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('nrm_code')->label('NRM')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('cost_type')
                    ->label('Cost type')
                    ->options([
                        CostCode::TYPE_LABOUR => 'Labour',
                        CostCode::TYPE_MATERIAL => 'Material',
                        CostCode::TYPE_PLANT => 'Plant',
                        CostCode::TYPE_SUBCONTRACT => 'Subcontract',
                        CostCode::TYPE_OTHER => 'Other',
                    ])
                    ->multiple(),

                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('Active only')
                    ->trueLabel('Active only')
                    ->falseLabel('Switched off')
                    ->queries(
                        true: fn (Builder $query) => $query->where('is_active', true),
                        false: fn (Builder $query) => $query->where('is_active', false),
                        blank: fn (Builder $query) => $query->where('is_active', true),
                    ),

                TernaryFilter::make('is_leaf')
                    ->label('Bookable only')
                    ->queries(
                        true: fn (Builder $query) => $query->where('is_leaf', true),
                        false: fn (Builder $query) => $query->where('is_leaf', false),
                        blank: fn (Builder $query) => $query,
                    ),

                // The gap-finder. §2.1's promise is that an unmapped code is visible, countable and fixable
                // rather than a silently short total in somebody's report — this is where it gets fixed.
                Filter::make('unmapped_icms')
                    ->label('Not mapped to ICMS')
                    ->query(fn (Builder $query) => $query->unmappedFor('icms_category')),

                Filter::make('unmapped_masterformat')
                    ->label('Not mapped to MasterFormat')
                    ->query(fn (Builder $query) => $query->unmappedFor('masterformat_code')),

                Filter::make('unmapped_nrm')
                    ->label('Not mapped to NRM')
                    ->query(fn (Builder $query) => $query->unmappedFor('nrm_code')),
            ])
            ->defaultSort('code')
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
