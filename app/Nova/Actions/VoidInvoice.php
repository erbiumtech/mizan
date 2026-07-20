<?php

namespace App\Nova\Actions;

use App\Services\InvoiceService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;

class VoidInvoice extends Action
{
    public $name = 'Void';

    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $service = app(InvoiceService::class);
        $done = 0;

        foreach ($models as $invoice) {
            try {
                $service->void($invoice, request()->user());
                $done++;
            } catch (\InvalidArgumentException $e) {
                return ActionResponse::danger($e->getMessage());
            }
        }

        return ActionResponse::message("Voided {$done} invoice(s).");
    }

    public function authorizedToRun(\Illuminate\Http\Request $request, $model): bool
    {
        return ($request->user()?->can('InvoiceVoid') ?? false) && $model->isOpen();
    }
}
