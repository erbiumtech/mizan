<?php

namespace App\Modules\Core\Filament\Resources\Roles\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\Roles\Pages\Concerns\SyncsGroupedPermissions;
use App\Modules\Core\Filament\Resources\Roles\RoleResource;
use App\Modules\Core\Filament\Resources\Roles\Schemas\RoleForm;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    use RedirectsToIndex, SyncsGroupedPermissions;

    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $byGroup = $this->record->permissions->groupBy('group');

        foreach (RoleForm::groupedPermissions()->keys() as $group) {
            $data[RoleForm::groupKey($group)] = ($byGroup[$group] ?? collect())
                ->pluck('id')
                ->all();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->syncGroupedPermissions();
    }
}
