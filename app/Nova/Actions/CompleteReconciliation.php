<?php

namespace App\Nova\Actions;

use App\Services\BankReconciliationService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class CompleteReconciliation extends Action
{
    public $name = 'Complete Reconciliation';

    public $confirmText = 'Complete this reconciliation? All lines must be matched or excluded and the closing balance must equal the ledger balance. This locks the statement.';

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(BankReconciliationService::class);

        foreach ($models as $statement) {
            try {
                $service->complete($statement, request()->user());
            } catch (\Exception $e) {
                return Action::danger($e->getMessage());
            }
        }

        return Action::message('Reconciliation completed.');
    }
}
