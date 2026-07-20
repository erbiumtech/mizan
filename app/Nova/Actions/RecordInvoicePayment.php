<?php

namespace App\Nova\Actions;

use App\Services\InvoiceService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Http\Requests\NovaRequest;

class RecordInvoicePayment extends Action
{
    public $name = 'Record Payment';

    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $service = app(InvoiceService::class);
        $done = 0;

        foreach ($models as $invoice) {
            try {
                $service->recordPayment($invoice, (float) $fields->amount, $fields->date);
                $done++;
            } catch (\InvalidArgumentException $e) {
                return ActionResponse::danger($e->getMessage());
            }
        }

        return ActionResponse::message("Payment recorded on {$done} invoice(s).");
    }

    public function fields(NovaRequest $request): array
    {
        return [
            Date::make('Date', 'date')->default(now()->toDateString())->rules('required', 'date'),
            Number::make('Amount', 'amount')->step(0.01)->rules('required', 'numeric', 'min:0.01'),
        ];
    }

    public function authorizedToRun(\Illuminate\Http\Request $request, $model): bool
    {
        return ($request->user()?->can('InvoicePay') ?? false) && $model->isOpen();
    }
}
