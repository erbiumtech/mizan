<?php

namespace App\Modules\Payroll\Filament\Resources\PayrollRuns\Tables;

use App\Modules\Payroll\Models\PayrollRun;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PayrollRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // The Period column renders `periodLabel()`, which reads the fiscal year — one query per row
            // without this, and a lazy-loading violation with the guard on.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('fiscalYear'))
            ->columns([
                TextColumn::make('month')
                    ->label(__('Period'))
                    ->state(fn (PayrollRun $record): string => $record->periodLabel())
                    ->sortable(),

                TextColumn::make('payslips_count')
                    ->label(__('Payslips'))
                    ->counts('payslips')
                    ->alignEnd(),

                // How much of the month the employees have actually agreed to, which
                // is the question worth answering before signing it off.
                TextColumn::make('accepted')
                    ->label(__('Accepted'))
                    // One call, not two: totals() is five aggregates, and asking twice per row doubled
                    // them. It is memoised on the model now, but reading it once here says so.
                    ->state(function (PayrollRun $record): string {
                        $totals = $record->totals();

                        return $totals['accepted'].' of '.$totals['payslips'];
                    })
                    ->alignEnd(),

                TextColumn::make('gross')
                    ->label(__('Gross'))
                    ->state(fn (PayrollRun $record): string => number_format($record->totals()['gross'], 2))
                    ->alignEnd(),

                TextColumn::make('net')
                    ->label(__('Net'))
                    ->state(fn (PayrollRun $record): string => number_format($record->totals()['net'], 2))
                    ->weight('bold')
                    ->alignEnd(),

                // The employer's contributions on top of the month — what the run costs beyond net and deductions.
                TextColumn::make('employer_cost')
                    ->label(__('Employer cost'))
                    ->state(fn (PayrollRun $record): string => number_format($record->totals()['employer_cost'], 2))
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PayrollRun::STATUS_LOCKED => 'success',
                        PayrollRun::STATUS_APPROVED => 'info',
                        default => 'warning',
                    })
                    ->description(fn (PayrollRun $record): ?string => match (true) {
                        $record->isLocked() => 'signed off '.$record->locked_at?->format('d M Y'),
                        $record->isApproved() => 'approved '.$record->approved_at?->format('d M Y'),
                        $record->sent_back_reason !== null => 'sent back: '.$record->sent_back_reason,
                        $record->reopen_reason !== null => 'reopened: '.$record->reopen_reason,
                        default => null,
                    }),

                // Inputs newer than the run last read — docs/saas-launch-plan.md §14.1. Named, so the
                // reader knows what moved; a locked run is not judged, because its figures are history.
                TextColumn::make('inputs')
                    ->label(__('Inputs'))
                    ->badge()
                    ->state(fn (PayrollRun $record): string => $record->isLocked() ? 'frozen' : ($record->isStale() ? 'changed' : 'current'))
                    ->color(fn (string $state): string => match ($state) {
                        'changed' => 'danger',
                        'current' => 'success',
                        default => 'gray',
                    })
                    ->tooltip(fn (PayrollRun $record): ?string => $record->isLocked() ? null : (($stale = $record->staleInputs()) === []
                        ? ($record->inputs_as_of ? 'read '.$record->inputs_as_of->format('d M H:i') : null)
                        : 'Changed since the run read them: '.collect($stale)->map(fn ($at, $what): string => "{$what} ({$at->format('d M H:i')})")->join(', '))),

                TextColumn::make('pay_day')
                    ->label(__('Pay day'))
                    ->state(fn (PayrollRun $record): ?string => $record->payDay()?->format('d M Y'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('locker.name')->label(__('Signed off by'))->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    PayrollRun::STATUS_OPEN => 'Open',
                    PayrollRun::STATUS_APPROVED => 'Approved',
                    PayrollRun::STATUS_LOCKED => 'Signed off',
                ]),
            ])
            ->recordActions([
                // Approve and send back — docs/saas-launch-plan.md §14.1. A second pair of eyes between
                // raising a month and freezing it; whether signing off needs it is a company switch.
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon('heroicon-o-check-badge')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription(fn (PayrollRun $record): string => 'You have read '.$record->periodLabel().' and its figures are right. '
                        .($record->isStale() ? 'Inputs have changed since the run read them — '.implode(', ', array_keys($record->staleInputs())).'. ' : '')
                        .'Signing off is a separate step.')
                    ->visible(fn (PayrollRun $record): bool => $record->isOpen()
                        && (auth()->user()?->can('approve', $record) ?? false))
                    ->action(function (PayrollRun $record): void {
                        try {
                            $record->approve(auth()->user());
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title($record->periodLabel().' approved.')->send();
                    }),

                Action::make('sendBack')
                    ->label(__('Send back'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (PayrollRun $record): bool => $record->isApproved()
                        && (auth()->user()?->can('approve', $record) ?? false))
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('What needs fixing?'))
                            ->required()
                            ->rows(3)
                            ->helperText('Kept on the run, where the clerk reads it.'),
                    ])
                    ->action(function (PayrollRun $record, array $data): void {
                        try {
                            $record->sendBack(auth()->user(), $data['reason']);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->warning()->title($record->periodLabel().' sent back.')->send();
                    }),

                Action::make('lock')
                    ->label(__('Sign off'))
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
                    ->label(__('Reopen'))
                    ->icon('heroicon-o-lock-open')
                    ->color('danger')
                    ->visible(fn (PayrollRun $record): bool => $record->isLocked()
                        && (auth()->user()?->can(PayrollRun::LOCK_PERMISSION) ?? false))
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('Why is a signed-off month being changed?'))
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
