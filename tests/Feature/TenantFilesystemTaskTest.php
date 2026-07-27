<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Multitenancy\Tasks\SwitchTenantFilesystemTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantFilesystemTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_disk_roots_are_scoped_per_tenant_and_restored(): void
    {
        $company = Company::factory()->create();
        $task = new SwitchTenantFilesystemTask();

        $task->makeCurrent($company);

        $this->assertSame(storage_path('app/public/tenants/'.$company->id), config('filesystems.disks.public.root'));
        // Not `/storage/...`: URLs point at the access-checked streaming route,
        // so no `public/storage` symlink is involved.
        $this->assertSame('/files/'.$company->id, config('filesystems.disks.public.url'));
        $this->assertSame(storage_path('app/private/tenants/'.$company->id), config('filesystems.disks.local.root'));

        $task->forgetCurrent();

        $this->assertSame(storage_path('app/public'), config('filesystems.disks.public.root'));
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
    }
}
