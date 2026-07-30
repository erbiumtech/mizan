<?php

namespace App\Modules\Core\Filament\Resources\Users\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
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
