<?php

namespace App\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;

class DownloadPayslip extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Download Payslip';

    public function handle(ActionFields $fields, Collection $models)
    {
        $payslip = $models->first();

        $cleanPayPeriod = str_replace([' ', '/', '\\'], '-', $payslip->pay_period);

        $customEmpId = $payslip->employee->employee_id;

        // Format: EMP-2-June-2025.pdf
        $fileName = 'payslips/' . $customEmpId . '-' . $cleanPayPeriod . '.pdf';

        if (!Storage::disk('public')->exists($fileName)) {

            if (!Storage::disk('public')->exists('payslips')) {
                Storage::disk('public')->makeDirectory('payslips');
            }

            $absolutePath = Storage::disk('public')->path($fileName);

            Pdf::view('pdfs.payslip', ['data' => $payslip])
               ->format('a4')
               ->save($absolutePath);

            $payslip->update([
                'pdf_path' => $fileName
            ]);
        }

        $downloadUrl = url(Storage::url($fileName));

        return Action::download($downloadUrl, $customEmpId . '-' . $cleanPayPeriod . '.pdf');
    }

    public function fields(NovaRequest $request)
    {
        return [];
    }
}
