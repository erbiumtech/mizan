<?php

namespace App\Modules\Core\Filament\Resources\Users\Tables;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

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
                //
                // An option list is a leak of its own: these name and address
                // every user they load, whatever the rows below are filtered to.
                // Scoped explicitly rather than left to the resource query, which
                // this does not go through — same two scopes it applies, see
                // User::scopeInCurrentCompany() and scopeExceptPlatformAdmins().
                SelectFilter::make('name')
                    ->label('User Name')
                    ->options(fn (): array => User::inCurrentCompany()->exceptPlatformAdmins()->orderBy('name')->pluck('name', 'id')->toArray()),

                SelectFilter::make('email')
                    ->label('User Email')
                    ->attribute('id')
                    ->options(fn (): array => User::inCurrentCompany()->exceptPlatformAdmins()->orderBy('email')->pluck('email', 'id')->toArray()),
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
                    self::removeFromCompanyBulkAction(),

                    // Deleting the account itself reaches every company the person
                    // works for, so it stays with the people who work across them.
                    DeleteBulkAction::make()
                        ->label('Delete accounts entirely')
                        ->modalDescription('Deletes these accounts from the whole installation, not just this company. Everything of theirs that survives — payslips, MPRs, audit entries — is left pointing at a user that no longer exists.')
                        ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false),
                ]),
            ]);
    }

    /**
     * "Delete" on a company's Users page means removing the person from this
     * company — see User::removeFromCompany() for why it cannot mean deleting the
     * account. Yourself excepted: that is the one removal that locks the person
     * doing it out of the page they are standing on.
     */
    public static function removeFromCompanyBulkAction(): BulkAction
    {
        return BulkAction::make('removeFromCompany')
            ->label('Remove from company')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Remove from company')
            ->modalDescription(fn (): string => 'They lose access to '.(Filament::getTenant()?->name ?? 'this company')
                .' and the roles they hold in it. Their account and their employee record stay — this does not touch the other companies they work for.')
            ->modalSubmitActionLabel('Remove')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $company = Filament::getTenant();

                if (! $company instanceof Company) {
                    return;
                }

                $removable = $records->reject(fn (User $record): bool => $record->getKey() === auth()->id());

                $removable->each(fn (User $record) => $record->removeFromCompany($company));

                Notification::make()
                    ->title($removable->count() === 1
                        ? '1 person removed from '.$company->name
                        : $removable->count().' people removed from '.$company->name)
                    ->body($removable->count() === $records->count()
                        ? null
                        : 'Your own membership was left alone — removing it would lock you out of this company.')
                    ->success()
                    ->send();
            });
    }
}
