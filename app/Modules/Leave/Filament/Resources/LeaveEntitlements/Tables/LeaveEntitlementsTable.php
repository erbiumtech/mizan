<?php

namespace App\Modules\Leave\Filament\Resources\LeaveEntitlements\Tables;

use App\Modules\Leave\Models\LeaveAdjustment;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalance;
use App\Support\LandlordUserColumn;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeaveEntitlementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')
                    ->label('Employee')
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('leaveType.label')->label('Type')->sortable(),

                TextColumn::make('leave_year_start')
                    ->label('Leave year')
                    ->date('d M Y')
                    ->description(fn (LeaveEntitlement $record): string => 'to '.$record->leave_year_end->format('d M Y'))
                    ->sortable(),

                TextColumn::make('opening_days')->label('Opening')->alignEnd()->formatStateUsing(static::days())->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('carried_in_days')
                    ->label('Carried in')
                    ->alignEnd()
                    ->formatStateUsing(static::days())
                    ->toggleable(),

                TextColumn::make('accrued_days')->label('Accrued')->alignEnd()->formatStateUsing(static::days()),

                // Summed from the rows, never a column. Two adjustments in one year
                // both exist and both keep their reason, which is the whole reason
                // that table is a table.
                TextColumn::make('adjustments_sum_days')
                    ->label('Adjusted')
                    ->alignEnd()
                    ->sum('adjustments', 'days')
                    ->formatStateUsing(static::days())
                    ->placeholder('—'),

                // Computed on read, like an account balance. No stored column to
                // drift against the days actually taken.
                TextColumn::make('taken')
                    ->label('Taken')
                    ->alignEnd()
                    ->state(fn (LeaveEntitlement $record): string => static::format(static::breakdown($record)?->taken ?? 0)),

                TextColumn::make('remaining')
                    ->label('Left')
                    ->alignEnd()
                    ->weight('bold')
                    ->state(fn (LeaveEntitlement $record): string => static::format(static::breakdown($record)?->remaining() ?? 0))
                    ->color(fn (LeaveEntitlement $record): string => (static::breakdown($record)?->remaining() ?? 0) < 0 ? 'danger' : 'success')
                    // Pending is shown beside the balance rather than deducted from
                    // it: a balance that moved when somebody merely *asked* would
                    // show the same last day as gone to two different people.
                    ->description(function (LeaveEntitlement $record): ?string {
                        $pending = static::breakdown($record)?->pending ?? 0;

                        return $pending > 0 ? static::format($pending).' awaiting a decision' : null;
                    }),
            ])
            ->defaultSort('leave_year_start', 'desc')
            ->filters([
                SelectFilter::make('leave_type_id')
                    ->label('Type')
                    ->options(fn (): array => LeaveType::orderBy('sort')->pluck('label', 'id')->all()),

                Filter::make('current_year')
                    ->label('Current leave year')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->covering(now())),
            ])
            ->recordActions([
                // The only way a balance is corrected. Deliberately an insert, never
                // an edit: "who gave me these three days and when" has to stay
                // answerable, and a mistake is fixed by a second adjustment in the
                // opposite direction rather than by overwriting the first.
                Action::make('adjust')
                    ->label('Adjust')
                    ->icon('heroicon-o-plus-circle')
                    ->color('warning')
                    ->visible(fn (): bool => auth()->user()?->can('create', LeaveAdjustment::class) ?? false)
                    ->schema([
                        TextInput::make('days')
                            ->label('Days')
                            ->numeric()
                            ->step(0.5)
                            ->required()
                            ->helperText('Negative to take days away. Half days are allowed; anything finer cannot be spent.'),

                        TextInput::make('reason')
                            ->label('Reason')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Kept for good. This is the answer to "who gave me these days and why".'),
                    ])
                    ->action(function (LeaveEntitlement $record, array $data): void {
                        $record->adjustments()->create([
                            'days' => $data['days'],
                            'reason' => $data['reason'],
                        ]);

                        Notification::make()->success()
                            ->title('Adjustment recorded against this leave year.')
                            ->send();
                    }),
            ]);
    }

    /**
     * The computed breakdown for a row, memoised per request.
     *
     * Three columns ask for it — taken, left, and the pending description — and each
     * one costs two aggregate queries. Without this the table would issue six per row.
     *
     * @var array<int, \App\Modules\Leave\Services\LeaveBalanceBreakdown|null>
     */
    private static array $cache = [];

    private static function breakdown(LeaveEntitlement $record): ?\App\Modules\Leave\Services\LeaveBalanceBreakdown
    {
        $key = (int) $record->getKey();

        if (! array_key_exists($key, static::$cache)) {
            static::$cache[$key] = $record->employee && $record->leaveType
                ? app(LeaveBalance::class)->for($record->employee, $record->leaveType, $record->leave_year_start)
                : null;
        }

        return static::$cache[$key];
    }

    private static function days(): callable
    {
        return fn ($state): string => static::format((float) $state);
    }

    /** Half-day granularity without trailing noise: 3, not 3.0. */
    private static function format(float $days): string
    {
        return rtrim(rtrim(number_format($days, 1), '0'), '.');
    }
}
