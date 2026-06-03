<?php

namespace App\Nova\Actions;

use App\Processors\PayrollProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

class RecalculatePayroll extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Recalculate Payroll';

    public function handle(ActionFields $fields, Collection $models)
    {
        foreach ($models as $payroll) {
            if (function_exists('payroll')) {
                payroll($payroll->id)->calculate();
            } elseif (class_exists(PayrollProcessor::class)) {
                (new PayrollProcessor($payroll))->calculate();
            }
        }

        return Action::message('Payroll(s) recalculated successfully.');
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}

