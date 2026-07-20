<?php

namespace App\Nova\Actions;

use App\Models\Payslip;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class RejectPayslip extends Action
{
    public $name = 'Reject Payslip';

    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        foreach ($models as $payslip) {
            try {
                $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, $fields->reason);
            } catch (\InvalidArgumentException $e) {
                return ActionResponse::danger($e->getMessage());
            }
        }

        return ActionResponse::message('Objection recorded; the accounts team will review it.');
    }

    public function fields(NovaRequest $request): array
    {
        return [
            Textarea::make('Reason', 'reason')->rules('required', 'max:255')
                ->help('Tell the accounts team what looks wrong'),
        ];
    }

    public function authorizedToRun(\Illuminate\Http\Request $request, $model): bool
    {
        return $model->isPendingReview()
            && $model->employee?->user_id === $request->user()?->id;
    }
}
