<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Providers\Filament\AdminPanelProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The panel as an installable app — docs/desktop-mobile-plan.md M1.
 *
 * Three promises: the manifest wears the company it is asked for (name, start URL) and falls back to the
 * installation when asked for none; the worker is served from the root with the header that lets it control
 * the whole origin; and the offline page renders without a session, because that is the one moment a
 * session cannot be checked.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_wears_the_company_it_is_asked_for(): void
    {
        $company = Company::factory()->create(['name' => 'Karachi Fabrics', 'slug' => 'karachi-fabrics']);

        $this->get('/manifest.webmanifest?company='.$company->slug)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', 'Karachi Fabrics')
            ->assertJsonPath('start_url', '/admin/karachi-fabrics')
            ->assertJsonPath('id', '/admin/karachi-fabrics')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('theme_color', AdminPanelProvider::BRAND_GREEN)
            ->assertJsonCount(2, 'icons');
    }

    public function test_the_manifest_falls_back_to_the_installation_when_no_company_is_named(): void
    {
        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertJsonPath('name', config('app.name'))
            ->assertJsonPath('start_url', '/admin');

        $this->get('/manifest.webmanifest?company=nobody-here')
            ->assertOk()
            ->assertJsonPath('start_url', '/admin');
    }

    public function test_the_service_worker_is_served_from_the_root_as_javascript(): void
    {
        $response = $this->get('/sw.js')
            ->assertOk()
            ->assertHeader('Service-Worker-Allowed', '/');

        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('application/javascript', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString("addEventListener('push'", $response->getContent());
        $this->assertStringContainsString("addEventListener('notificationclick'", $response->getContent());
        $this->assertStringContainsString('/offline', $response->getContent());
    }

    public function test_the_offline_page_renders_without_a_session(): void
    {
        $this->get('/offline')
            ->assertOk()
            ->assertSee("You're offline", false)
            ->assertSee('Try again');
    }
}
