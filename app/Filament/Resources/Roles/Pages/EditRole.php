<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\Pages\Concerns\SyncsGroupedPermissions;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    use SyncsGroupedPermissions;

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
