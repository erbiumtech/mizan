<?php

namespace App\Modules\Core\Filament\Resources\Users\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\Users\UserResource;
use App\Modules\Core\Models\Company;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Not DeleteAction: the account is shared with every other company
            // this person works for. See User::removeFromCompany().
            Action::make('removeFromCompany')
                ->label('Remove from company')
                ->icon('heroicon-o-user-minus')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Remove from company')
                ->modalDescription(fn (): string => $this->record->name.' loses access to '
                    .(Filament::getTenant()?->name ?? 'this company').' and the roles they hold in it. Their '
                    .'account and their employee record stay — this does not touch the other companies they work for.')
                ->modalSubmitActionLabel('Remove')
                // Removing your own membership locks you out of the page you are on.
                ->visible(fn (): bool => $this->record->getKey() !== auth()->id())
                ->action(function (): void {
                    $company = Filament::getTenant();

                    if (! $company instanceof Company) {
                        return;
                    }

                    $this->record->removeFromCompany($company);

                    Notification::make()
                        ->title($this->record->name.' was removed from '.$company->name)
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),

            // Deleting the account itself reaches every company the person works
            // for, so it stays with the people who work across them.
            DeleteAction::make()
                ->label('Delete account entirely')
                ->modalDescription('Deletes this account from the whole installation, not just this company. Everything of theirs that survives — payslips, MPRs, audit entries — is left pointing at a user that no longer exists.')
                ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false),
        ];
    }

    /**
     * Pre-fill the (per-current-company) roles multi-select from the user's
     * current-team role assignments.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['roles'] = $this->record->roles()->pluck('name')->all();

        return $data;
    }

    /**
     * Sync roles for the current company (spatie teams honours the active team id).
     */
    protected function afterSave(): void
    {
        $this->record->syncRoles($this->data['roles'] ?? []);
    }
}
