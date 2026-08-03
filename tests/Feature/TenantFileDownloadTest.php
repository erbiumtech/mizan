<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Multitenancy\Tasks\SwitchTenantFilesystemTask;
use App\Support\TenantStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stored files are streamed through the app instead of a `public/storage`
 * symlink, so they work on hosts where `storage:link` cannot be created — and,
 * unlike the symlink, one company's files are not readable by another.
 */
class TenantFileDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function putFile(Company $company, string $path, string $contents = '%PDF-1.4 fake'): string
    {
        $full = TenantStorage::publicRoot($company).'/'.$path;
        File::ensureDirectoryExists(dirname($full));
        File::put($full, $contents);

        return $full;
    }

    private function member(Company $company): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company);

        return $user;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/public/tenants'));

        parent::tearDown();
    }

    public function test_a_member_can_download_their_companys_file(): void
    {
        $company = Company::factory()->create();
        $this->putFile($company, 'payslips/january.pdf');

        $this->actingAs($this->member($company))
            ->get("/files/{$company->id}/payslips/january.pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_nested_paths_resolve(): void
    {
        $company = Company::factory()->create();
        $this->putFile($company, 'mprs/2026/07/report.pdf');

        $this->actingAs($this->member($company))
            ->get("/files/{$company->id}/mprs/2026/07/report.pdf")
            ->assertOk();
    }

    public function test_a_non_member_cannot_read_another_companys_file(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();
        $this->putFile($theirs, 'payslips/secret.pdf');

        $this->actingAs($this->member($mine))
            ->get("/files/{$theirs->id}/payslips/secret.pdf")
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $company = Company::factory()->create();
        $this->putFile($company, 'payslips/january.pdf');

        $this->get("/files/{$company->id}/payslips/january.pdf")
            ->assertRedirect();
    }

    public function test_a_missing_file_is_a_404(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->member($company))
            ->get("/files/{$company->id}/payslips/nope.pdf")
            ->assertNotFound();
    }

    /** The route pattern allows slashes, so traversal has to be rejected. */
    public function test_traversal_out_of_the_company_directory_is_refused(): void
    {
        $company = Company::factory()->create();
        File::ensureDirectoryExists(storage_path('app/public/tenants'));
        File::put(storage_path('app/public/tenants/outside.txt'), 'nope');

        $this->actingAs($this->member($company))
            ->get("/files/{$company->id}/../outside.txt")
            ->assertNotFound();

        $this->assertFalse(TenantStorage::isSafePath('../outside.txt'));
        $this->assertFalse(TenantStorage::isSafePath('a/../../b'));
        $this->assertTrue(TenantStorage::isSafePath('mprs/2026/report.pdf'));
    }

    public function test_download_flag_forces_an_attachment(): void
    {
        $company = Company::factory()->create();
        $this->putFile($company, 'payslips/january.pdf');

        $this->actingAs($this->member($company))
            ->get("/files/{$company->id}/payslips/january.pdf?download=1")
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=january.pdf');
    }

    /** The URL the app hands out must be the route, not a symlink path. */
    public function test_the_public_disk_generates_route_urls_for_the_current_tenant(): void
    {
        $company = Company::factory()->create();
        (new SwitchTenantFilesystemTask)->makeCurrent($company);

        $this->assertSame(
            "/files/{$company->id}/payslips/january.pdf",
            Storage::disk('public')->url('payslips/january.pdf'),
        );
    }
}
