<?php

namespace App\Modules\Core\Filament\Resources\Users\Tables;

use App\Modules\Core\Models\User;
use Filament\Actions\Action;
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
                    ->visible(fn (): bool => auth()->user()?->hasAnyRole(['Administrator', 'Manager', 'CEO']) ?? false)
                    ->action(fn (User $record) => $record->update([
                        'status' => (int) $record->status === 1 ? 0 : 1,
                    ])),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
