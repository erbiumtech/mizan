<?php

namespace App\Modules\Advances\Filament\Resources\Advances\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Advances\Filament\Resources\Advances\AdvanceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAdvance extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = AdvanceResource::class;

    /**
     * An application — docs/saas-launch-plan.md §14.3. Somebody who may only create files a request for
     * their own record; whoever may update records a given advance as before.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if ($user && ! $user->can('AdvanceUpdate')) {
            $own = \App\Modules\Employees\Models\Employee::query()->where('user_id', $user->getKey())->value('id');
            $data['employee_id'] = $own ?? $data['employee_id'];
            $data['status'] = \App\Modules\Advances\Models\Advance::STATUS_REQUESTED;
            $data['requested_by'] = $user->getKey();
            $data['requested_at'] = now();
        }

        return $data;
    }
}
