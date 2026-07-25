<?php

namespace App\Filament\Resources\Roles\Pages\Concerns;

use App\Filament\Resources\Roles\Schemas\RoleForm;

trait SyncsGroupedPermissions
{
    /**
     * Collect selected permission IDs across every per-group CheckboxList in
     * the raw form state (the fields are dehydrated:false, so they never touch
     * the role's own attributes).
     *
     * @return array<int, int>
     */
    protected function selectedPermissionIds(): array
    {
        $ids = [];

        foreach (RoleForm::groupedPermissions()->keys() as $group) {
            $value = $this->data[RoleForm::groupKey($group)] ?? [];

            if (is_array($value)) {
                $ids = array_merge($ids, $value);
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    protected function syncGroupedPermissions(): void
    {
        $this->record->permissions()->sync($this->selectedPermissionIds());
    }
}
