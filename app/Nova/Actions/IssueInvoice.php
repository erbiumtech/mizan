<?php

namespace App\Nova\Actions;

use App\Services\InvoiceService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;

class IssueInvoice extends Action
{
    public $name = 'Issue';

    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $service = app(InvoiceService::class);
        $done = 0;

        foreach ($models as $invoice) {
            try {
                $service->issue($invoice);
                $done++;
            } catch (\InvalidArgumentException $e) {
                return ActionResponse::danger($e->getMessage());
            }
        }

        return ActionResponse::message("Issued {$done} invoice(s).");
    }

    public function authorizedToRun(\Illuminate\Http\Request $request, $model): bool
    {
        return ($request->user()?->can('InvoiceIssue') ?? false) && $model->isDraft();
    }
}
