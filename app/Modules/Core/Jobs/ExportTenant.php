<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Notifications\RecordChanged;
use App\Notifications\TenantExportReady;
use App\Support\TenantExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Throwable;

/**
 * Build a company's export and tell the person who asked — docs/saas-launch-plan.md §9.
 *
 * Queued: a dump plus a zip of every upload is minutes for a big company.
 * `NotTenantAware`, because the service reads the landlord row and reaches the
 * tenant's database through the backup connection and its files through their
 * paths — nothing here needs the tenant to be current, and a job that did would
 * break the console command that runs the same service for any company.
 */
class ExportTenant implements NotTenantAware, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $companyId, public int $userId) {}

    public function handle(TenantExport $exports): void
    {
        $company = Company::query()->find($this->companyId);
        $user = User::query()->find($this->userId);

        if ($company === null) {
            return;
        }

        $path = $exports->run($company);

        if ($user === null) {
            return;
        }

        $latest = $exports->latest($company);
        $url = $exports->url($company, $path);

        $user->notify(new TenantExportReady($company->name, $url, (int) ($latest['bytes'] ?? 0)));
        $user->notify(new RecordChanged('Your data export is ready', "{$company->name}: the archive is in the company's storage.", $url));
    }

    public function failed(?Throwable $exception): void
    {
        $user = User::query()->find($this->userId);
        $company = Company::query()->find($this->companyId);

        $user?->notify(new RecordChanged(
            'Your data export failed',
            ($company?->name ?? 'The company').': '.($exception?->getMessage() ?? 'unknown error').' This has been reported.',
        ));
    }
}
