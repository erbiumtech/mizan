<?php

namespace App\Nova\Actions;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

class ApprovePayment extends Action
{
    public $name = 'Approve Payment';

    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $service = app(PaymentService::class);
        $approved = 0;

        foreach ($models as $payment) {
            if ($payment->status !== Payment::STATUS_DRAFT) {
                continue;
            }

            $service->approve($payment);
            $approved++;
        }

        return ActionResponse::message("Approved {$approved} payment(s) and booked their journal entries.");
    }

    public function authorizedToRun(\Illuminate\Http\Request $request, $model): bool
    {
        return $request->user()?->can('PaymentUpdate') ?? false;
    }
}
