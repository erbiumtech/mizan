<?php

namespace App\Modules\Core\Filament\Platform\Resources\Users\Tables;

use App\Modules\Core\Models\User;
use App\Support\Impersonation;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PlatformUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),

                IconColumn::make('is_super_admin')
                    ->label('Platform')
                    ->boolean(),

                // The question this screen is for: who is this person, across the
                // installation. On the company panel the answer is always "here".
                TextColumn::make('companies.name')
                    ->label('Companies')
                    ->badge()
                    ->placeholder('none — cannot sign in anywhere')
                    ->limitList(3)
                    ->expandableLimitedList(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn ($state): string => $state ? 'success' : 'gray')
                    ->sortable(),

                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('companies')
                    ->label('Company')
                    ->relationship('companies', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('is_super_admin')
                    ->label('Platform admins')
                    ->query(fn ($query) => $query->where('is_super_admin', true)),

                // An account attached to nothing can sign in nowhere, which is easy to
                // create by forgetting the last field on the form and invisible afterwards.
                Filter::make('no_company')
                    ->label('In no company')
                    ->query(fn ($query) => $query->whereDoesntHave('companies')),
            ])
            ->recordActions([
                self::impersonateAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Deleting the last platform admin would lock everybody out of this
                    // panel, including whoever did it. Refused on the User model, not
                    // here: Gate::before grants a super admin every ability, so a rule
                    // that has to hold for everyone cannot live in a policy — and it must
                    // hold for the console and a queued job too.
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    /**
     * Sign in as this user, from anywhere in the installation.
     *
     * The cross-company half of impersonation, which used to be done from inside whichever
     * company happened to be open — a super admin standing in one customer's URL signing in
     * as another customer's staff. It belongs here, where the list is every account and the
     * only people who can open the page are the ones allowed to do it.
     *
     * A company's own Administrator keeps the action on their own Users list for their own
     * staff: acknowledging a salary change on behalf of somebody is a company workflow, and
     * routing it through the platform operator would put an outside party's name on a
     * statement of consent.
     *
     * Asks the service rather than the Gate, because Gate::before grants a super admin every
     * ability and `can()` would therefore be true for every row — including another super
     * admin, the one row that must never offer it.
     */
    protected static function impersonateAction(): Action
    {
        return Action::make('impersonate')
            ->label('Log in as')
            ->icon('heroicon-o-arrow-right-end-on-rectangle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => 'Log in as '.$record->name)
            ->modalDescription('You will be signed in as this user, in one of their own companies, until '
                .'you stop. Everything you do is recorded against them and against you — including salary '
                .'acknowledgements, which are a statement of consent.')
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

                // Their own company, not this panel: the person you are now is not a
                // platform admin and would be refused at the door.
                return redirect(Filament::getPanel('admin')->getUrl());
            });
    }
}
