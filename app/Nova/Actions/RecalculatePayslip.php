<?php

namespace App\Nova\Actions;

use App\Processors\PayslipProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

class RecalculatePayslip extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Recalculate Payslip';

    public function handle(ActionFields $fields, Collection $models)
    {
        foreach ($models as $payslip) {
            if (function_exists('payslip')) {
                payslip($payslip->id)->calculate();
            } elseif (class_exists(PayslipProcessor::class)) {
                (new PayslipProcessor($payslip))->calculate();
            }
        }

        return Action::message('Payslip(s) recalculated successfully.');
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}

