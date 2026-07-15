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

        $month = $payslip->month;
        $yearName = $payslip->fiscalYear ? $payslip->fiscalYear->name : 'Unknown-Year';

        $cleanFileNamePart = $month . '-' . str_replace([' ', '/', '\\'], '-', $yearName);

        $customEmpId = $payslip->employee->employee_id;

        // Format: EMP-2-January-2026-2027.pdf
        $fileName = 'payslips/' . $customEmpId . '-' . $cleanFileNamePart . '.pdf';

        if (!Storage::disk('public')->exists($fileName)) {

            if (!Storage::disk('public')->exists('payslips')) {
                Storage::disk('public')->makeDirectory('payslips');
            }

            $absolutePath = Storage::disk('public')->path($fileName);

            Pdf::view('pdfs.payslip', ['data' => $payslip])
               ->format('a4')
            ->withBrowsershot(fn (\Spatie\Browsershot\Browsershot $b) => $b->setNodeBinary(config('services.node.binary'))->setNpmBinary(config('services.node.npm')))
               ->save($absolutePath);

            $payslip->update([
                'pdf_path' => $fileName
            ]);
        }

        $downloadUrl = url(Storage::url($fileName));

        return Action::download($downloadUrl, $customEmpId . '-' . $cleanFileNamePart . '.pdf');
    }

    public function fields(NovaRequest $request)
    {
        return [];
    }
}
