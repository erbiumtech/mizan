<?php

namespace App\Nova\Actions;

use App\Models\Payslip;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;

class AcceptPayslip extends Action
{
    public $name = 'Accept Payslip';

    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        foreach ($models as $payslip) {
            try {
                $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);
            } catch (\InvalidArgumentException $e) {
                return ActionResponse::danger($e->getMessage());
            }
        }

        return ActionResponse::message('Payslip accepted — thank you.');
    }

    public function authorizedToRun(\Illuminate\Http\Request $request, $model): bool
    {
        return $model->isPendingReview()
            && $model->employee?->user_id === $request->user()?->id;
    }
}
