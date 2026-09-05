<?php

use App\Modules\Core\Models\Company;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

// Sweeps the temporary MPR comparison exports off each company's public disk once they are an hour old.
//
// Per company, because the scheduler has no tenant of its own and the `public` disk without one is the
// shared root — which is where the sweep used to look, and where the API used to write. Both now work
// inside a company (ResolveCompanyFromUser for the API, execute() here), so the files are found where
// they land. Hourly rather than every minute: a company switch per company per run is not free, and a
// temp file living two hours instead of one costs nothing.
Schedule::call(function (): void {
    Company::query()->each(function (Company $company): void {
        $company->execute(function (): void {
            $disk = Storage::disk('public');

            foreach ($disk->files('Mpr') as $file) {
                if (str_contains($file, '_Comparison_') && now()->timestamp - $disk->lastModified($file) >= 3600) {
                    $disk->delete($file);
                }
            }
        });
    });
})->hourly()->name('mpr:sweep-comparison-exports')->withoutOverlapping();
