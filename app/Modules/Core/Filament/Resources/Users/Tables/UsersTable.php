<?php

namespace App\Modules\Core\Filament\Resources\Users\Tables;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\CompanyInvitations;
use App\Support\Impersonation;
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
                    ->label(__('ID'))
                    ->sortable(),

                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('email')
                    ->sortable()
                    ->searchable(),

                // Nova Password field hideFromIndex -> not shown in table.

                IconColumn::make('status')
                    ->label(__('Active'))
                    ->boolean(),

                /*
                 * Last seen — docs/saas-launch-plan.md §14.9.
                 *
                 * A column rather than a read of the sign-in log, because the question is
                 * "who has not been here since March" and answering that from the log means
                 * sorting every row in it. Stamped by LogAuthenticationEvent.
                 */
                TextColumn::make('last_login_at')
                    ->label(__('Last seen'))
                    ->dateTime('d M Y H:i')
                    ->placeholder('never')
                    ->description(fn (User $record): ?string => $record->last_login_ip)
                    ->sortable(),
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
                    ->label(__('User Name'))
                    ->options(fn (): array => User::inCurrentCompany()->exceptPlatformAdmins()->orderBy('name')->pluck('name', 'id')->toArray()),

                SelectFilter::make('email')
                    ->label(__('User Email'))
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
                    ->label(__('Log in as'))
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

                /*
                 * Send the invitation again — §14.9.
                 *
                 * The same call the invitation itself makes, which is what makes it safe to
                 * offer: a person who already has an account is sent a sign-in link and a
                 * person who never set a password is sent a fresh one, and neither path
                 * creates anything twice.
                 *
                 * Offered only to somebody who has never signed in. An invitation to a person
                 * who uses this every day is a password-reset mail they did not ask for.
                 */
                Action::make('resendInvitation')
                    ->label(__('Send the invitation again'))
                    ->icon('heroicon-o-envelope')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('They get a fresh link to set a password and sign in. Their roles are not changed.')
                    ->visible(fn (User $record): bool => $record->last_login_at === null
                        && (auth()->user()?->can('UserCreate') ?? false))
                    ->action(function (User $record): void {
                        $company = Filament::getTenant() ?? Company::current();

                        if ($company === null) {
                            return;
                        }

                        app(CompanyInvitations::class)->invite(
                            $company,
                            (string) $record->email,
                            (string) $record->name,
                            $record->roles->pluck('name')->all(),
                            auth()->user(),
                        );

                        Notification::make()->success()->title('Sent again to '.$record->email)->send();
                    }),

                /*
                 * Reset two-factor — §14.9.
                 *
                 * The one thing an administrator has to be able to do that a person cannot do
                 * for themselves: somebody who has lost the phone with the authenticator on it
                 * is locked out, and the recovery codes are on a piece of paper they also lost.
                 *
                 * It clears rather than replaces. The next sign-in makes them set it up again,
                 * so nobody — including whoever clicked this — ever holds their secret.
                 */
                Action::make('resetTwoFactor')
                    ->label(__('Reset two-factor'))
                    ->icon('heroicon-o-device-phone-mobile')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reset two-factor authentication')
                    ->modalDescription('Their authenticator app and recovery codes stop working, and they set it '
                        .'up again on their next sign-in. Make sure you know who you are talking to: this is the '
                        .'step somebody pretending to be them would ask for.')
                    ->modalSubmitActionLabel('Reset it')
                    ->visible(fn (User $record): bool => $record->app_authentication_secret !== null
                        && (auth()->user()?->isAdministrator() ?? false))
                    ->action(function (User $record): void {
                        $record->forceFill([
                            'app_authentication_secret' => null,
                            'app_authentication_recovery_codes' => null,
                        ])->save();

                        activity('User')->performedOn($record)->causedBy(auth()->user())
                            ->event('two-factor-reset')->log('Two-factor authentication reset');

                        Notification::make()->success()->title('Reset. They set it up again next time they sign in.')->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::removeFromCompanyBulkAction(),

                    // Deleting the account itself reaches every company the person
                    // works for, so it stays with the people who work across them.
                    DeleteBulkAction::make()
                        ->label(__('Delete accounts entirely'))
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
            ->label(__('Remove from company'))
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
