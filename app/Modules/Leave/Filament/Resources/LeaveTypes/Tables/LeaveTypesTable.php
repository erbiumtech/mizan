<?php

namespace App\Modules\Leave\Filament\Resources\LeaveTypes\Tables;

use App\Modules\Leave\Models\LeaveType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class LeaveTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->searchable()->sortable(),

                TextColumn::make('days_per_year')
                    ->label('Days / year')
                    ->alignEnd()
                    // Not 0: unlimited and none have no annual figure at all, and
                    // showing 0 would read as an entitlement of nothing.
                    ->placeholder('not counted')
                    ->formatStateUsing(fn ($state): string => rtrim(rtrim(number_format((float) $state, 1), '0'), '.'))
                    ->sortable(),

                TextColumn::make('accrual_method')
                    ->label('Earned')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        LeaveType::ACCRUAL_ANNUAL_UPFRONT => 'whole year at once',
                        LeaveType::ACCRUAL_MONTHLY => 'a twelfth monthly',
                        LeaveType::ACCRUAL_ON_COMPLETION => 'after 12 months',
                        LeaveType::ACCRUAL_COMPENSATORY => 'working a day off',
                        LeaveType::ACCRUAL_UNLIMITED => 'not counted',
                        default => 'no entitlement',
                    })
                    // Said in the list, not only in the form: a type that cannot
                    // credit anything yet should not look like one that can.
                    ->description(fn (LeaveType $record): ?string => $record->accrual_method === LeaveType::ACCRUAL_COMPENSATORY
                        ? 'needs Attendance — credits nothing today'
                        : null),

                IconColumn::make('is_paid')->label('Paid')->boolean()->sortable(),

                TextColumn::make('max_carry_forward')
                    ->label('May carry')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => (float) $state > 0
                        ? rtrim(rtrim(number_format((float) $state, 1), '0'), '.').' days'
                        : '—')
                    ->description(fn (LeaveType $record): ?string => $record->carriesForward() && ! setting('leave.carry_forward')
                        // The cap is set but the company switch is off, so it does
                        // nothing — which otherwise looks like a bug in the reset.
                        ? 'carry-forward is off for this company'
                        : null)
                    ->toggleable(),

                TextColumn::make('min_notice_days')
                    ->label('Notice')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => (int) $state > 0 ? $state.' days' : '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('requires_document')->label('Document')->boolean()->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_encashable')->label('Encashable')->boolean()->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
            ])
            ->defaultSort('sort')
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('is_paid')->label('Paid'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
