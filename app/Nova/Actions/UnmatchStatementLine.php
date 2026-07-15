<?php

namespace App\Nova\Actions;

use App\Services\BankReconciliationService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class UnmatchStatementLine extends Action
{
    public $name = 'Unmatch';

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(BankReconciliationService::class);

        foreach ($models as $line) {
            try {
                $service->unmatch($line);
            } catch (\Exception $e) {
                return Action::danger($e->getMessage());
            }
        }

        return Action::message('Statement line unmatched.');
    }
}
