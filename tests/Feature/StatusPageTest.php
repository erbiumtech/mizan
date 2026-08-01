<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Multitenancy\Tasks\SetPermissionsTeamIdTask;
use App\Multitenancy\Tasks\SwitchTenantFilesystemTask;
use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\MakesProjects;
use Tests\TestCase;

/**
 * The security-critical surface: the only part of this feature reachable without
 * authentication. The assertions that matter most are the negative ones.
 */
class StatusPageTest extends TestCase
{
    use MakesProjects;
    use RefreshDatabase;

    private Company $company;

    private string $token = 'test-status-token-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        // The route makes the company current, which normally switches to its
        // own database. The suite runs on a single database, so the DB-switch
        // and cache-prefix tasks are dropped here; everything this file asserts
        // (gating, whitelisting, caching, tenant cleanup) is unaffected.
        config(['multitenancy.switch_tenant_tasks' => [
            SetPermissionsTeamIdTask::class,
            SwitchTenantFilesystemTask::class,
        ]]);

        $this->company = Company::factory()->create(['slug' => 'acme']);
        Cache::flush();
    }

    private function enablePage(): void
    {
        $settings = app(TenantSettings::class);
        $settings->set('projects.status_page.enabled', true);
        $settings->set('projects.status_page.token', $this->token);
    }

    private function url(?string $token = null): string
    {
        return '/status/'.$this->company->slug.'/'.($token ?? $this->token);
    }

    public function test_the_page_is_off_by_default(): void
    {
        $this->makeEnvironment($this->makeProject(), ['is_public' => true]);

        $this->get($this->url())->assertNotFound();
    }

    public function test_a_wrong_token_is_not_found(): void
    {
        $this->enablePage();

        $this->get($this->url('wrong-token'))->assertNotFound();
    }

    public function test_an_unknown_company_is_not_found(): void
    {
        $this->enablePage();

        $this->get('/status/nope/'.$this->token)->assertNotFound();
    }

    public function test_only_public_environments_are_listed(): void
    {
        $this->enablePage();

        $project = $this->makeProject(['name' => 'Public project']);
        $public = $this->makeEnvironment($project, ['kind' => 'prod', 'is_public' => true]);
        $public->recordCheck(true, 200, 42);

        $this->makeEnvironment($project, ['kind' => 'dev', 'is_public' => false]);

        $hidden = $this->makeProject(['name' => 'Unpublished project']);
        $this->makeEnvironment($hidden, ['kind' => 'prod', 'is_public' => false]);

        $response = $this->get($this->url())->assertOk();

        $response->assertSee('Public project');
        $response->assertSee('Production');
        $response->assertSee('operational');
        $response->assertDontSee('Development');
        $response->assertDontSee('Unpublished project');
    }

    public function test_no_credentials_urls_or_error_text_ever_reach_the_page(): void
    {
        $this->enablePage();

        $environment = $this->makeEnvironment($this->makeProject(), [
            'is_public' => true,
            'url' => 'https://internal-prod-7.corp.local',
            'username' => 'svc_admin',
            'password' => 'sup3r-s3cret',
        ]);
        $environment->recordCheck(false, 500, 90, 'cURL error 7: failed to connect to internal-prod-7.corp.local');

        $body = $this->get($this->url())->assertOk()->getContent();

        $this->assertStringNotContainsString('sup3r-s3cret', $body);
        $this->assertStringNotContainsString('svc_admin', $body);
        $this->assertStringNotContainsString('internal-prod-7.corp.local', $body);
        $this->assertStringNotContainsString('cURL', $body);

        // But it does report the outage.
        $this->assertStringContainsString('outage', $body);
    }

    public function test_uptime_reads_no_data_before_the_first_check(): void
    {
        $this->enablePage();
        $this->makeEnvironment($this->makeProject(), ['is_public' => true]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('no data')
            ->assertSee('unknown');
    }

    public function test_the_payload_is_cached(): void
    {
        $this->enablePage();
        $project = $this->makeProject(['name' => 'Cached project']);
        $this->makeEnvironment($project, ['is_public' => true]);

        $this->get($this->url())->assertOk()->assertSee('Cached project');

        // A project added after the first render is not shown until the cache
        // expires — the point of the cache, asserted so it can't silently break.
        $this->makeEnvironment($this->makeProject(['name' => 'Later project']), ['is_public' => true]);

        $this->get($this->url())->assertOk()->assertDontSee('Later project');

        Cache::forget('status-page:'.$this->company->getKey());

        $this->get($this->url())->assertOk()->assertSee('Later project');
    }

    public function test_the_tenant_is_not_left_current_after_the_request(): void
    {
        $this->enablePage();
        $this->makeEnvironment($this->makeProject(), ['is_public' => true]);

        $this->get($this->url())->assertOk();

        $this->assertNull(Company::current());
    }
}
