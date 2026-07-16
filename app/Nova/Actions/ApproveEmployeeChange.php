<?php

namespace App\Nova\Actions;

use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;

class ApproveEmployeeChange extends Action
{
    public $name = 'Approve';

    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $done = 0;

        foreach ($models as $request) {
            try {
                $request->approve(request()->user());
                $done++;
            } catch (\InvalidArgumentException $e) {
                return ActionResponse::danger($e->getMessage());
            }
        }

        return ActionResponse::message("Approved {$done} change request(s).");
    }

    public function authorizedToRun(\Illuminate\Http\Request $request, $model): bool
    {
        return ($request->user()?->can('EmployeeChangeApprove') ?? false) && $model->isPending();
    }
}
