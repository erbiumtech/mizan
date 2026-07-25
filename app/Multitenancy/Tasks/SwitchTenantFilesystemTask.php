<?php

namespace App\Multitenancy\Tasks;

use Illuminate\Support\Facades\Storage;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

/**
 * Isolates file storage per company. Reroutes the `public` and `local` disks to
 * a per-tenant sub-directory (and the public URL to a matching path) whenever a
 * tenant is made current, and restores the shared defaults when forgotten. This
 * keeps one company's payslip/MPR PDFs, receipts and import files from
 * colliding with (or being downloadable by) another company.
 */
class SwitchTenantFilesystemTask implements SwitchTenantTask
{
    public function makeCurrent(IsTenant $tenant): void
    {
        $suffix = 'tenants/'.$tenant->getKey();

        config([
            'filesystems.disks.public.root' => storage_path('app/public/'.$suffix),
            'filesystems.disks.public.url' => rtrim(config('app.url'), '/').'/storage/'.$suffix,
            'filesystems.disks.local.root' => storage_path('app/private/'.$suffix),
        ]);

        $this->forgetDisks();
    }

    public function forgetCurrent(): void
    {
        config([
            'filesystems.disks.public.root' => storage_path('app/public'),
            'filesystems.disks.public.url' => rtrim(config('app.url'), '/').'/storage',
            'filesystems.disks.local.root' => storage_path('app/private'),
        ]);

        $this->forgetDisks();
    }

    protected function forgetDisks(): void
    {
        Storage::forgetDisk(['public', 'local']);
    }
}
