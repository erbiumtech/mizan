<?php

namespace App\Nova\Actions;

use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class RejectEmployeeChange extends Action
{
    public $name = 'Reject';

    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $done = 0;

        foreach ($models as $request) {
            try {
                $request->reject(request()->user(), $fields->reason);
                $done++;
            } catch (\InvalidArgumentException $e) {
                return ActionResponse::danger($e->getMessage());
            }
        }

        return ActionResponse::message("Rejected {$done} change request(s).");
    }

    public function fields(NovaRequest $request): array
    {
        return [
            Text::make('Reason', 'reason')->nullable(),
        ];
    }

    public function authorizedToRun(\Illuminate\Http\Request $request, $model): bool
    {
        return ($request->user()?->can('EmployeeChangeApprove') ?? false) && $model->isPending();
    }
}
