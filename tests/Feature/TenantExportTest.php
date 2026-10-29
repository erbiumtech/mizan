<?php

namespace Tests\Feature;

use App\Modules\Core\Jobs\ExportTenant;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Multitenancy\CompanyProvisioner;
use App\Multitenancy\TenantAwareJobs;
use App\Notifications\RecordChanged;
use App\Notifications\TenantExportReady;
use App\Support\TenantExport;
use App\Support\TenantStorage;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use ZipArchive;

/**
 * A company's data as one archive — docs/saas-launch-plan.md §9.
 *
 * Real sqlite tenants, so the dump is a real dump: the whole database, every
 * upload, nothing of anybody else's, streamed only to the company's own
 * Administrators, and the thing tenant:drop asks for before it drops.
 */
class TenantExportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['multitenancy.tenant_database_connection_name' => 'tenant']);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::factory()->create(['status' => 1]);
        $this->company = app(CompanyProvisioner::class)->provision(name: 'Export Co', slug: 'export-co', creator: $this->admin);

        File::ensureDirectoryExists(TenantStorage::publicRoot($this->company).'/payslips');
        File::put(TenantStorage::publicRoot($this->company).'/payslips/march.pdf', '%PDF-1.4 march');
        File::ensureDirectoryExists(TenantStorage::privateRoot($this->company).'/statement-imports');
        File::put(TenantStorage::privateRoot($this->company).'/statement-imports/hbl.csv', 'date,amount');
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();

        foreach (Company::all() as $company) {
            if ($company->database && File::exists($company->database)) {
                File::delete($company->database);
            }

            File::deleteDirectory(TenantStorage::publicRoot($company));
            File::deleteDirectory(TenantStorage::privateRoot($company));
        }

        parent::tearDown();
    }

    public function test_the_archive_holds_the_dump_and_every_upload_and_no_earlier_export(): void
    {
        $exports = app(TenantExport::class);

        $first = $exports->run($this->company);
        $this->assertStringStartsWith('exports/export-co-', $first);

        // A second export must not swallow the first.
        sleep(1);
        $second = $exports->run($this->company);
        $this->assertNotSame($first, $second);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(TenantStorage::publicRoot($this->company).'/'.$second));

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertContains('database.sql', $names);
        $this->assertContains('README.txt', $names);
        $this->assertContains('files/public/payslips/march.pdf', $names);
        $this->assertContains('files/private/statement-imports/hbl.csv', $names);
        $this->assertSame([], array_values(array_filter($names, fn (string $n): bool => str_starts_with($n, 'files/public/exports/'))), 'The archive contains an earlier archive.');

        $sql = $zip->getFromName('database.sql');
        $this->assertStringContainsStringIgnoringCase('CREATE TABLE', $sql);
        $this->assertStringContainsString('employees', $sql, 'The dump is not the tenant database.');
        $this->assertStringNotContainsString('companies', $sql, 'The dump reached the landlord.');
        $this->assertSame('%PDF-1.4 march', $zip->getFromName('files/public/payslips/march.pdf'));
        $zip->close();

        $latest = $exports->latest($this->company);
        $this->assertSame($second, $latest['path']);
        $this->assertGreaterThan(0, $latest['bytes']);
        $this->assertNull($exports->latest(Company::factory()->create()));
    }

    public function test_only_the_companys_administrators_can_download_it(): void
    {
        $path = app(TenantExport::class)->run($this->company);
        $url = TenantStorage::urlRoot($this->company).'/'.$path;

        // The Administrator the provisioner attached.
        $this->actingAs($this->admin)->get($url)->assertOk();

        // A member with the Employee role.
        $employee = User::factory()->create(['status' => 1]);
        $this->company->users()->attach($employee->getKey());
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        $employee->assignRole('Employee');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->actingAs($employee)->get($url)->assertForbidden();

        // Somebody from another company entirely.
        $this->actingAs(User::factory()->create(['status' => 1]))->get($url)->assertForbidden();

        // And an Administrator still reaches every other file as before.
        $this->actingAs($this->admin)->get(TenantStorage::urlRoot($this->company).'/payslips/march.pdf')->assertOk();
    }

    public function test_the_job_serves_the_landlord_and_tells_the_person_who_asked(): void
    {
        Notification::fake();

        $this->assertFalse(TenantAwareJobs::requiresTenant(new ExportTenant(1, 1)));

        (new ExportTenant($this->company->getKey(), $this->admin->getKey()))->handle(app(TenantExport::class));

        $this->assertNotNull(app(TenantExport::class)->latest($this->company));
        Notification::assertSentTo($this->admin, TenantExportReady::class, fn (TenantExportReady $n): bool => str_contains($n->url, '/files/'.$this->company->getKey().'/exports/') && $n->bytes > 0);
        Notification::assertSentTo($this->admin, RecordChanged::class);
    }

    public function test_the_command_exports_and_drop_refuses_a_company_that_was_never_exported(): void
    {
        $never = app(CompanyProvisioner::class)->provision(name: 'Never Exported', slug: 'never-exported', creator: $this->admin);

        $this->artisan('tenant:drop', ['tenant' => 'never-exported', '--force' => true])
            ->expectsOutputToContain('has never been exported')
            ->assertFailed();
        $this->assertTrue(File::exists($never->database), 'Dropped without an export.');

        $this->artisan('tenant:export', ['tenant' => 'never-exported'])
            ->expectsOutputToContain('Exported Never Exported')
            ->assertSuccessful();

        $this->artisan('tenant:drop', ['tenant' => 'never-exported', '--force' => true])
            ->expectsOutputToContain('Last export')
            ->assertSuccessful();
        $this->assertFalse(File::exists($never->database));

        // An explicit waiver drops an unexported company; an unknown slug is an error.
        $this->artisan('tenant:export', ['tenant' => 'nobody'])->assertFailed();
    }
}
