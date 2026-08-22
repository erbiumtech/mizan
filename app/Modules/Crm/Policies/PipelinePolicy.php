<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\Pipeline;

/**
 * Pipelines, their stages and the lost reasons share one group.
 *
 * They are one piece of setup — nobody grants "may rename a stage but not add a lost
 * reason" — and every salesperson needs View so the board and the forms can render.
 */
class PipelinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('PipelineView');
    }

    public function view(User $user, Pipeline $record): bool
    {
        return $user->hasPermissionTo('PipelineView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('PipelineCreate');
    }

    public function update(User $user, Pipeline $record): bool
    {
        return $user->hasPermissionTo('PipelineUpdate');
    }

    public function delete(User $user, Pipeline $record): bool
    {
        return $user->hasPermissionTo('PipelineDelete') && ! $record->opportunities()->exists();
    }
}
