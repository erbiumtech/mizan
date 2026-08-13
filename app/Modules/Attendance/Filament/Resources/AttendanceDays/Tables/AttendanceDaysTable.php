<?php

namespace App\Modules\Attendance\Filament\Resources\AttendanceDays\Tables;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Support\LandlordUserColumn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AttendanceDaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')
                    ->date('d M Y')
                    ->description(fn (AttendanceDay $record): string => $record->date->format('l'))
                    ->sortable(),

                TextColumn::make('employee.display_label')
                    ->label('Employee')
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AttendanceDay::STATUS_PRESENT, AttendanceDay::STATUS_WORK_FROM_HOME => 'success',
                        AttendanceDay::STATUS_HALF_DAY => 'info',
                        AttendanceDay::STATUS_ON_LEAVE => 'primary',
                        AttendanceDay::STATUS_ABSENT => 'danger',
                        // Grey, and deliberately not red. A day nobody answered for is
                        // not an absence, and colouring it like one is how the
                        // distinction stops being believed.
                        AttendanceDay::STATUS_NOT_MARKED => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        AttendanceDay::STATUS_PRESENT => 'Present',
                        AttendanceDay::STATUS_WORK_FROM_HOME => 'From home',
                        AttendanceDay::STATUS_HALF_DAY => 'Half day',
                        AttendanceDay::STATUS_ON_LEAVE => 'On leave',
                        AttendanceDay::STATUS_ABSENT => 'Absent',
                        AttendanceDay::STATUS_HOLIDAY => 'Holiday',
                        AttendanceDay::STATUS_WEEKLY_OFF => 'Weekly off',
                        default => 'Not marked',
                    })
                    ->description(fn (AttendanceDay $record): ?string => $record->isCoveredByLeave()
                        ? 'approved leave — not editable here'
                        : null)
                    ->sortable(),

                TextColumn::make('check_in_at')->label('In')->time('H:i')->placeholder('—')->toggleable(),
                TextColumn::make('check_out_at')->label('Out')->time('H:i')->placeholder('—')->toggleable(),

                TextColumn::make('worked_minutes')
                    ->label('Worked')
                    ->formatStateUsing(fn ($state): string => static::hours((int) $state))
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('overtime_minutes')
                    ->label('Overtime')
                    ->formatStateUsing(fn ($state): string => static::hours((int) $state))
                    ->alignEnd()
                    // Said plainly until phase 3a is switched on, so nobody assumes a
                    // recorded hour is a paid one.
                    ->description(fn (AttendanceDay $record): ?string => $record->overtime_minutes > 0
                        && ! setting('payroll.pay_overtime')
                            ? 'recorded, not paid'
                            : null)
                    ->toggleable(),

                TextColumn::make('late_minutes')
                    ->label('Late')
                    ->formatStateUsing(fn ($state): string => (int) $state > 0 ? $state.' min' : '—')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    AttendanceDay::STATUS_PRESENT => 'Present',
                    AttendanceDay::STATUS_ABSENT => 'Absent',
                    AttendanceDay::STATUS_ON_LEAVE => 'On leave',
                    AttendanceDay::STATUS_HALF_DAY => 'Half day',
                    AttendanceDay::STATUS_WORK_FROM_HOME => 'From home',
                    AttendanceDay::STATUS_HOLIDAY => 'Holiday',
                    AttendanceDay::STATUS_WEEKLY_OFF => 'Weekly off',
                    AttendanceDay::STATUS_NOT_MARKED => 'Not marked',
                ]),

                // The filter this module exists to make possible. "Which days has
                // nobody answered for" is the question a payroll clerk has to be able
                // to ask before trusting a month.
                Filter::make('unknown')
                    ->label('Not marked')
                    ->query(fn (Builder $query): Builder => $query->unknown()),

                Filter::make('this_month')
                    ->label('This month')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->whereBetween('date', [
                        now()->startOfMonth()->toDateString(),
                        now()->endOfMonth()->toDateString(),
                    ])),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Minutes as hours and minutes: 90 reads as 1h 30m, not as 1.5. */
    private static function hours(int $minutes): string
    {
        if ($minutes <= 0) {
            return '—';
        }

        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
