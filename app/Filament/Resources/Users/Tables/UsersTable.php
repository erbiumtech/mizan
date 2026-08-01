<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Support\Impersonation;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Facades\Filament;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('email')
                    ->sortable()
                    ->searchable(),

                // Nova Password field hideFromIndex -> not shown in table.

                IconColumn::make('status')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                // Parity with Nova UserNameFilter (options name => id) and UserEmailFilter (options email => id).
                SelectFilter::make('name')
                    ->label('User Name')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->toArray()),

                SelectFilter::make('email')
                    ->label('User Email')
                    ->attribute('id')
                    ->options(fn (): array => User::query()->orderBy('email')->pluck('email', 'id')->toArray()),
            ])
            ->recordActions([
                // Activate / deactivate an account. Only Administrator, Manager
                // and CEO may toggle it; a deactivated user (status = 0) can no
                // longer sign in (see User::canAccessPanel()).
                Action::make('toggleStatus')
                    ->label(fn (User $record): string => (int) $record->status === 1 ? 'Deactivate' : 'Activate')
                    ->icon(fn (User $record): string => (int) $record->status === 1 ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn (User $record): string => (int) $record->status === 1 ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->visible(function (): bool {
                        $user = auth()->user();

                        // A super admin holds no role in most companies; see
                        // User::isAdministrator() for why that has to be said here.
                        return (bool) $user?->isSuperAdmin()
                            || (bool) $user?->hasAnyRole(['Administrator', 'Manager', 'CEO']);
                    })
                    ->action(fn (User $record) => $record->update([
                        'status' => (int) $record->status === 1 ? 0 : 1,
                    ])),

                // Sign in as this user to complete something on their behalf.
                //
                // Asks the service, not the Gate. Gate::before grants an
                // Administrator every ability except 'create', so `can()` here
                // would be true for every row — including super admins, which is
                // the one row that must never offer it. start() re-checks anyway,
                // so a stale or forged request still cannot get through.
                Action::make('impersonate')
                    ->label('Log in as')
                    ->icon('heroicon-o-arrow-right-end-on-rectangle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => 'Log in as '.$record->name)
                    ->modalDescription('You will be signed in as this user until you stop. Everything you do is '
                        .'recorded against them and against you — including salary acknowledgements, which are a '
                        .'statement of consent.')
                    ->modalSubmitActionLabel('Log in as this user')
                    ->visible(fn (User $record): bool => app(Impersonation::class)->allows(auth()->user(), $record))
                    ->action(function (User $record) {
                        try {
                            app(Impersonation::class)->start($record);
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return null;
                        }

                        Notification::make()
                            ->warning()
                            ->title('You are now signed in as '.$record->name)
                            ->body('Use "Stop impersonating" in the banner to return to your own account.')
                            ->send();

                        return redirect(Filament::getPanel('admin')->getUrl());
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
