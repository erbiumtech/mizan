<?php

namespace App\Modules\Payroll\Filament\Resources\PayrollRuns\Tables;

use App\Modules\Payroll\Models\PayrollRun;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PayrollRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('month')
                    ->label('Period')
                    ->state(fn (PayrollRun $record): string => $record->periodLabel())
                    ->sortable(),

                TextColumn::make('payslips_count')
                    ->label('Payslips')
                    ->counts('payslips')
                    ->alignEnd(),

                // How much of the month the employees have actually agreed to, which
                // is the question worth answering before signing it off.
                TextColumn::make('accepted')
                    ->label('Accepted')
                    ->state(fn (PayrollRun $record): string => $record->totals()['accepted']
                        .' of '.$record->totals()['payslips'])
                    ->alignEnd(),

                TextColumn::make('gross')
                    ->label('Gross')
                    ->state(fn (PayrollRun $record): string => number_format($record->totals()['gross'], 2))
                    ->alignEnd(),

                TextColumn::make('net')
                    ->label('Net')
                    ->state(fn (PayrollRun $record): string => number_format($record->totals()['net'], 2))
                    ->weight('bold')
                    ->alignEnd(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === PayrollRun::STATUS_LOCKED ? 'success' : 'warning')
                    ->description(fn (PayrollRun $record): ?string => $record->isLocked()
                        ? 'signed off '.$record->locked_at?->format('d M Y')
                        : ($record->reopen_reason ? 'reopened: '.$record->reopen_reason : null)),

                TextColumn::make('locker.name')->label('Signed off by')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    PayrollRun::STATUS_OPEN => 'Open',
                    PayrollRun::STATUS_LOCKED => 'Signed off',
                ]),
            ])
            ->recordActions([
                Action::make('lock')
                    ->label('Sign off')
                    ->icon('heroicon-o-lock-closed')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (PayrollRun $record): string => 'Freezes '.$record->periodLabel()
                        .': its payslips cannot be changed, added to or deleted afterwards. '
                        .$record->totals()['accepted'].' of '.$record->totals()['payslips']
                        .' payslips have been accepted by their employee.')
                    ->visible(fn (PayrollRun $record): bool => ! $record->isLocked()
                        && (auth()->user()?->can(PayrollRun::LOCK_PERMISSION) ?? false))
                    ->action(function (PayrollRun $record): void {
                        try {
                            $record->lock(auth()->user());
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title($record->periodLabel().' signed off.')->send();
                    }),

                Action::make('reopen')
                    ->label('Reopen')
                    ->icon('heroicon-o-lock-open')
                    ->color('danger')
                    ->visible(fn (PayrollRun $record): bool => $record->isLocked()
                        && (auth()->user()?->can(PayrollRun::LOCK_PERMISSION) ?? false))
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why is a signed-off month being changed?')
                            ->required()
                            ->rows(3)
                            ->helperText('Kept on the run. This is the question an auditor asks about a month that was agreed and then changed.'),
                    ])
                    ->action(function (PayrollRun $record, array $data): void {
                        try {
                            $record->reopen(auth()->user(), $data['reason']);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->warning()->title($record->periodLabel().' is open again.')->send();
                    }),
            ]);
    }
}
