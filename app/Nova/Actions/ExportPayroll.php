<?php

namespace App\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Maatwebsite\Excel\Facades\Excel;

class ExportPayroll extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Export Payroll (Excel)';

    public function handle(ActionFields $fields, Collection $models)
    {
        $payroll = $models->first();

        if (! $payroll) {
            return Action::danger('Payroll not found.');
        }

        if (! class_exists(\App\Exports\PayrollExport::class)) {
            return Action::danger('PayrollExport class is not available.');
        }

        $fileName = 'payroll_' . now()->format('Y-m-d_His') . '.xlsx';
        $relative = 'exports/' . $fileName;

        Excel::store(new \App\Exports\PayrollExport($payroll->id), $relative, 'public');

        return Action::download(url('storage/' . $relative), $fileName);
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}

