<?php

namespace App\Nova\Actions;

use App\Services\BankReconciliationService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class ExcludeStatementLine extends Action
{
    public $name = 'Exclude';

    public $confirmText = 'Exclude this line from reconciliation (e.g. a bank fee with no ledger entry)?';

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(BankReconciliationService::class);

        foreach ($models as $line) {
            try {
                $service->exclude($line);
            } catch (\Exception $e) {
                return Action::danger($e->getMessage());
            }
        }

        return Action::message('Statement line excluded.');
    }
}
