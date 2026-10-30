<?php

namespace App\Modules\Advances\Filament\Resources\Advances\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Advances\Filament\Resources\Advances\AdvanceResource;
use App\Modules\Advances\Models\Advance;
use App\Modules\Advances\Models\AdvanceType;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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

        // An advance recorded as already given skips approval, which is where the type's limits were checked —
        // so they are checked here, the same check approve() makes.
        if (($data['status'] ?? null) === Advance::STATUS_ACTIVE && ($type = AdvanceType::query()->find($data['advance_type_id'] ?? null))) {
            try {
                $type->check((float) $data['total_amount'], filled($data['instalments'] ?? null) ? (int) $data['instalments'] : null, (int) $data['employee_id']);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['data.total_amount' => $e->getMessage()]);
            }
        }

        return $data;
    }
}
