<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\PipelineStage;

/**
 * Pipelines, their stages and the lost reasons share one group.
 *
 * They are one piece of setup — nobody grants "may rename a stage but not add a lost
 * reason" — and every salesperson needs View so the board and the forms can render.
 */
class PipelineStagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('PipelineView');
    }

    public function view(User $user, PipelineStage $record): bool
    {
        return $user->hasPermissionTo('PipelineView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('PipelineCreate');
    }

    public function update(User $user, PipelineStage $record): bool
    {
        return $user->hasPermissionTo('PipelineUpdate');
    }

    public function delete(User $user, PipelineStage $record): bool
    {
        return $user->hasPermissionTo('PipelineDelete') && ! $record->opportunities()->exists();
    }
}
