<?php

namespace App\Multitenancy\Tasks;

use App\Support\TenantStorage;
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
        config([
            'filesystems.disks.public.root' => TenantStorage::publicRoot($tenant->getKey()),
            // Points at the streaming route, not `public/storage`, so every
            // existing `Storage::disk('public')->url()` yields an access-checked
            // URL that works without the `storage:link` symlink.
            'filesystems.disks.public.url' => TenantStorage::urlRoot($tenant->getKey()),
            'filesystems.disks.local.root' => TenantStorage::privateRoot($tenant->getKey()),
        ]);

        $this->forgetDisks();
    }

    public function forgetCurrent(): void
    {
        config([
            'filesystems.disks.public.root' => storage_path('app/public'),
            'filesystems.disks.public.url' => '/storage',
            'filesystems.disks.local.root' => storage_path('app/private'),
        ]);

        $this->forgetDisks();
    }

    protected function forgetDisks(): void
    {
        Storage::forgetDisk(['public', 'local']);
    }
}
