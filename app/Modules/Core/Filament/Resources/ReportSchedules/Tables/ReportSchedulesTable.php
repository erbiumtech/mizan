<?php

namespace App\Modules\Core\Filament\Resources\ReportSchedules\Tables;

use App\Modules\Core\Models\ReportSchedule;
use App\Support\Reporting\RelativePeriod;
use App\Support\Reporting\ReportDeliveryService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The schedules, and whether they are still working — Phase 8, items 1, 2 and 8.
 *
 * **The suspension is a column rather than a footnote**, because it is the one state somebody has to act on:
 * item 2 suspends a schedule whose owner has lost access rather than rendering it with fewer rows, and a
 * suspended schedule that looked active in this list would be a report nobody knew had stopped.
 */
class ReportSchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('report_key')
                    ->label('Report')
                    ->state(fn (ReportSchedule $record): string => app(ReportDeliveryService::class)->titleFor($record))
                    ->description(fn (ReportSchedule $record): string => RelativePeriod::label($record->period))
                    ->searchable(),

                TextColumn::make('cron')
                    ->label('Timetable')
                    ->state(fn (ReportSchedule $record): string => $record->timetable()),

                TextColumn::make('format')
                    ->label('Format')
                    ->state(fn (ReportSchedule $record): string => ReportSchedule::FORMATS[$record->format] ?? (string) $record->format),

                TextColumn::make('recipients')
                    ->label('Recipients')
                    ->state(fn (ReportSchedule $record): string => (string) count((array) $record->recipients)),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('suspended_at')
                    ->label('State')
                    ->badge()
                    ->state(fn (ReportSchedule $record): string => $record->isSuspended() ? 'Suspended' : 'Running')
                    ->color(fn (ReportSchedule $record): string => $record->isSuspended() ? 'danger' : 'success')
                    // Why, in the description, because "suspended" without a reason is a support ticket.
                    ->description(fn (ReportSchedule $record): ?string => $record->suspended_reason),

                TextColumn::make('last_run_at')
                    ->label('Last sent')
                    ->dateTime('j M Y H:i')
                    ->placeholder('Never'),
            ])
            ->recordActions([
                EditAction::make(),

                /*
                 * Resume, which is deliberately not automatic.
                 *
                 * A schedule is suspended because its owner lost access to the report; the fix is a permission
                 * or a licence, and nothing here can tell whether that has happened. So resuming is somebody
                 * saying "I have fixed it" — and if they have not, the next run suspends it again with the
                 * same reason, which is a better answer than a schedule that keeps retrying quietly.
                 */
                Action::make('resume')
                    ->label('Resume')
                    ->icon('heroicon-m-play')
                    ->color('gray')
                    ->visible(fn (ReportSchedule $record): bool => $record->isSuspended())
                    ->action(function (ReportSchedule $record): void {
                        $record->resume();

                        Notification::make()
                            ->success()
                            ->title('Resumed. If the owner still cannot read the report, the next run will suspend it again.')
                            ->send();
                    }),

                DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }
}
