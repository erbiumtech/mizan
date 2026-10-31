<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The front door. With the public site on (the default) `/` is its home page; with it off, the
 * redirect into the admin panel that every installation had before docs/company-website-plan.md.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_renders_the_public_home_page(): void
    {
        $this->get('/')->assertOk()->assertSee('Start a');
    }

    public function test_the_root_redirects_into_the_admin_panel_when_the_site_is_off(): void
    {
        config()->set('site.enabled', false);

        $this->get('/')->assertRedirect('/admin');
    }
}
