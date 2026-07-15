<?php

namespace App\Nova\Actions;

use App\Services\BankReconciliationService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

class AutoMatchStatement extends Action
{
    public $name = 'Auto-Match';

    public $confirmText = 'Auto-match unmatched statement lines against the ledger (exact amount + date within 3 days, or amount + reference)?';

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(BankReconciliationService::class);
        $total = 0;

        foreach ($models as $statement) {
            try {
                $total += $service->autoMatch($statement);
            } catch (\Exception $e) {
                return Action::danger($e->getMessage());
            }
        }

        return Action::message("Auto-matched {$total} line(s).");
    }
}
