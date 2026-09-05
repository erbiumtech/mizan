<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveCompanyFromUser;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Mpr\Models\MPR;
use App\Multitenancy\Tasks\SetPermissionsTeamIdTask;
use App\Multitenancy\Tasks\SwitchTenantFilesystemTask;
use App\Support\TenantStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * API requests run as the caller's company — see ResolveCompanyFromUser.
 *
 * The storage assertions are the point. An MPR report requested over the API must land under the company's
 * own directory and be linked through the access-checked route — never `/storage/…` in the shared root,
 * which is where `storage/app/public/Mpr/<Name>_<timestamp>.pdf` was found, written by this API with no
 * company current.
 */
class ApiCompanyResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['slug' => 'acme']);
        $this->user = User::factory()->create();
        $this->company->users()->attach($this->user);
        $this->enable($this->company);

        // Single-database suite: make the tenant switch real without a second database, so activate() runs
        // the filesystem task — otherwise the `public` disk stays the shared root and the assertions below
        // are about nothing. The DB switch task is dropped, as ModuleEnforcementTest does, and the tenant
        // connection is named as the suite's own, so it *is* the one database. Set after the fixtures so
        // nothing on the landlord side sees a tenant connection while they are created.
        config([
            'multitenancy.tenant_database_connection_name' => config('database.default'),
            'multitenancy.switch_tenant_tasks' => [SetPermissionsTeamIdTask::class, SwitchTenantFilesystemTask::class],
            'pdf.driver' => 'dompdf',
        ]);
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();
        File::deleteDirectory(storage_path('app/public/tenants'));

        parent::tearDown();
    }

    private function enable(Company $company): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $company->getKey(), 'module' => 'mpr'],
            ['licensed' => true, 'enabled' => true],
        );

        modules()->flush();
    }

    public function test_a_report_lands_in_the_callers_company_directory_and_is_linked_through_the_route(): void
    {
        $mpr = MPR::create(['user_id' => $this->user->getKey(), 'mpr_date' => '2026-08-01', 'feedback' => 'Fine']);

        $url = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/my-mprs/{$mpr->getKey()}")
            ->assertOk()
            ->json('data.pdf_url');

        // url() trims a trailing slash, so the slash goes on afterwards.
        $prefix = url("/files/{$this->company->getKey()}").'/';

        $this->assertStringStartsWith($prefix.'Mpr/', $url, 'the URL must be the access-checked route for this company');
        $this->assertStringNotContainsString('/storage/', $url);

        $path = Str::after($url, $prefix);

        $this->assertTrue(TenantStorage::publicDisk($this->company)->exists($path), 'the file must be under tenants/{id}');
        $this->assertFileDoesNotExist(storage_path('app/public/'.$path), 'nothing may be written to the shared root');

        // Remembered, so the next request serves the same file instead of rendering again.
        $this->assertSame($path, $mpr->fresh()->pdf_path);
        $this->assertSame($url, $this->getJson("/api/my-mprs/{$mpr->getKey()}")->assertOk()->json('data.pdf_url'));

        // And the link works with the same token the API call used.
        $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_company_does_not_outlive_the_request(): void
    {
        $this->actingAs($this->user, 'sanctum')->getJson('/api/my-mprs')->assertOk();

        $this->assertNull(Company::current());
    }

    public function test_a_member_of_several_companies_must_name_one(): void
    {
        $beta = Company::factory()->create(['slug' => 'beta']);
        $beta->users()->attach($this->user);
        $this->enable($beta);

        $this->actingAs($this->user, 'sanctum');

        $this->getJson('/api/my-mprs')
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, ResolveCompanyFromUser::HEADER));

        $mpr = MPR::create(['user_id' => $this->user->getKey()]);

        $url = $this->withHeader(ResolveCompanyFromUser::HEADER, 'beta')
            ->getJson("/api/my-mprs/{$mpr->getKey()}")
            ->assertOk()
            ->json('data.pdf_url');

        $this->assertStringStartsWith(url("/files/{$beta->getKey()}/Mpr").'/', $url, 'the named company, not the first one');
    }

    public function test_naming_a_company_the_caller_is_not_in_is_refused(): void
    {
        Company::factory()->create(['slug' => 'other']);

        $this->actingAs($this->user, 'sanctum');

        $this->withHeader(ResolveCompanyFromUser::HEADER, 'other')->getJson('/api/my-mprs')->assertForbidden();
        $this->withHeader(ResolveCompanyFromUser::HEADER, 'nope')->getJson('/api/my-mprs')->assertForbidden();
    }
}
