<?php

namespace App\Nova\Actions;

use App\Services\MprPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

class DownloadSingleMprPdf extends Action
{
    use InteractsWithQueue, Queueable;

    // Row dropdown me jo naam nazar aye ga
    public $name = 'Download PDF';

    public function handle(ActionFields $fields, Collection $models)
    {
        $mprRecord = $models->first();

        if (!$mprRecord) {
            return Action::danger('Record not found!');
        }

        $pdfService = new MprPdfService();
        $result = $pdfService->generateSingleReport($mprRecord->toArray());

        // File save aur new tab me open
        $result['pdf']->save(storage_path('app/public/' . $result['file_name']));
        return Action::openInNewTab(url('storage/' . $result['file_name']));
    }

    public function fields(NovaRequest $request)
    {
        return []; // Bilkul khali taake bina kisi popup modal ke direct click par download ho
    }
}
