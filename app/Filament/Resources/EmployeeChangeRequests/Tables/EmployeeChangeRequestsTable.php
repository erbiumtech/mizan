<?php

namespace App\Filament\Resources\EmployeeChangeRequests\Tables;

use App\Models\EmployeeChangeRequest;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class EmployeeChangeRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('employee.user'))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('employee.employee_id')
                    ->label('Employee')
                    ->formatStateUsing(fn ($state, $record) => $record->employee?->display_label ?? $state)
                    ->sortable(),

                TextColumn::make('target_type')
                    ->label('Changes To')
                    ->badge()
                    ->color(fn ($record): string => $record->targetsSetting() ? 'warning' : 'gray')
                    ->formatStateUsing(fn ($state, $record): string => $record->targetLabel())
                    ->sortable(),

                TextColumn::make('requester.name')
                    ->label('Requested By'),

                TextColumn::make('requested_changes')
                    ->label('Requested Changes')
                    ->formatStateUsing(fn ($state): string => is_array($state)
                        ? collect($state)->map(fn ($v, $k) => "{$k}: {$v}")->implode(', ')
                        : (string) $state)
                    ->wrap(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('reviewer.name')
                    ->label('Reviewed By')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reviewed_at')
                    ->label('Reviewed At')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('rejection_reason')
                    ->label('Rejection Reason')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Requested At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('viewChanges')
                    ->label('View Changes')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('Requested Changes')
                    ->modalContent(fn (EmployeeChangeRequest $record) => view('filament.employee-change-diff', ['record' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (EmployeeChangeRequest $record): bool => (auth()->user()?->can('EmployeeChangeApprove') ?? false) && $record->isPending())
                    ->action(fn (EmployeeChangeRequest $record) => self::run(fn () => $record->approve(auth()->user()), 'Approved 1 change request(s).')),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (EmployeeChangeRequest $record): bool => (auth()->user()?->can('EmployeeChangeApprove') ?? false) && $record->isPending())
                    ->schema([
                        TextInput::make('reason')->label('Reason')->nullable(),
                    ])
                    ->action(fn (array $data, EmployeeChangeRequest $record) => self::run(fn () => $record->reject(auth()->user(), $data['reason'] ?? null), 'Rejected 1 change request(s).')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approveBulk')
                        ->label('Approve')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => auth()->user()?->can('EmployeeChangeApprove') ?? false)
                        ->action(fn (Collection $records) => self::runBulk($records, fn (EmployeeChangeRequest $r) => $r->approve(auth()->user()), 'Approved')),

                    BulkAction::make('rejectBulk')
                        ->label('Reject')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->visible(fn (): bool => auth()->user()?->can('EmployeeChangeApprove') ?? false)
                        ->schema([
                            TextInput::make('reason')->label('Reason')->nullable(),
                        ])
                        ->action(fn (array $data, Collection $records) => self::runBulk($records, fn (EmployeeChangeRequest $r) => $r->reject(auth()->user(), $data['reason'] ?? null), 'Rejected')),
                ]),
            ]);
    }

    /**
     * Run a single change-request operation, surfacing service validation errors as notifications.
     */
    protected static function run(callable $op, string $message): void
    {
        try {
            $op();
            Notification::make()->title($message)->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    protected static function runBulk(Collection $records, callable $op, string $label): void
    {
        $done = 0;
        try {
            foreach ($records as $record) {
                $op($record);
                $done++;
            }
            Notification::make()->title("{$label} {$done} change request(s).")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
