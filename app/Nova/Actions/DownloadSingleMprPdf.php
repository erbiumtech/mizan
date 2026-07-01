<?php

namespace App\Nova\Actions;

use App\Services\MprPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Illuminate\Support\Facades\Storage;

class DownloadSingleMprPdf extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Download PDF';

    public function handle(ActionFields $fields, Collection $models)
    {
        $mprRecord = $models->first();

        if (!$mprRecord) {
            return Action::danger('Record not found!');
        }

        $userName = $mprRecord->user->name ?? 'User';
        $cleanName = str_replace([' ', '/', '\\'], '_', $userName);

        $fileName = 'Mpr/' . $cleanName . '_' . time() . '.pdf';

        if ($mprRecord->pdf_path && Storage::disk('public')->exists($mprRecord->pdf_path)) {
            $fileName = $mprRecord->pdf_path;
        } else {
            $pdfService = new MprPdfService();
            $result = $pdfService->generateSingleReport($mprRecord->toArray());

            $directory = storage_path('app/public/Mpr');

            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            $result['pdf']->save(storage_path('app/public/' . $fileName));

            $mprRecord->update(['pdf_path' => $fileName]);
        }

        return Action::openInNewTab(url('storage/' . $fileName));
    }

    public function fields(NovaRequest $request)
    {
        return [];
    }
}
