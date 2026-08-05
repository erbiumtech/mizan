<?php

namespace App\Modules\Core\Filament\Resources\Roles\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\Roles\Pages\Concerns\SyncsGroupedPermissions;
use App\Modules\Core\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    use RedirectsToIndex, SyncsGroupedPermissions;

    protected static string $resource = RoleResource::class;

    /**
     * A role created here belongs to the company it was created in.
     *
     * Spatie stamps the team from its registrar, which is request state — and a role
     * stamped null belongs to no company, appears in no list and can be assigned to
     * nobody. Naming the company explicitly is one line and does not depend on what
     * happened earlier in the request.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data[config('permission.column_names.team_foreign_key', 'company_id')]
            ??= RoleResource::currentCompanyId();

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncGroupedPermissions();
    }
}
