<?php

namespace App\Modules\Core\Filament\Resources\Roles\Pages\Concerns;

use App\Modules\Core\Filament\Resources\Roles\Schemas\RoleForm;

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

    /**
     * Permissions the role holds that the form did not render — the groups
     * belonging to modules this company has switched off.
     *
     * Without this, the sync() below would treat "not on the form" as
     * "deselected" and detach them. Saving any role while Accounting was off
     * would quietly strip every accounting permission from it, and switching the
     * module back on would find each role unconfigured with nothing to point at
     * as the cause.
     *
     * @return array<int, int>
     */
    protected function preservedPermissionIds(): array
    {
        if (! $this->record?->exists) {
            return [];
        }

        $rendered = RoleForm::groupedPermissions()->keys()->all();

        return $this->record->permissions
            // A permission with no group is invisible on the form too, so it is
            // preserved for the same reason.
            ->reject(fn ($permission) => in_array((string) $permission->group, $rendered, true))
            ->pluck('id')
            ->map('intval')
            ->all();
    }

    protected function syncGroupedPermissions(): void
    {
        $this->record->permissions()->sync([
            ...$this->selectedPermissionIds(),
            ...$this->preservedPermissionIds(),
        ]);
    }
}
