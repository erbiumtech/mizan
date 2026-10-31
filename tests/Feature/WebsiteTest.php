<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Platform\Resources\ContactMessages\Pages\ListContactMessages;
use App\Modules\Core\Models\ContactMessage;
use App\Modules\Core\Models\SitePage;
use App\Modules\Core\Models\SiteSetting;
use App\Modules\Core\Models\User;
use App\Notifications\ContactMessageReceived;
use App\Support\Modules;
use App\Support\Plans;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The public site — docs/company-website-plan.md.
 *
 * What is pinned: every page renders and says what config says; nothing hand-written leaks
 * before it is published; the contact form keeps the signup door's three rules; the switch
 * really switches the site off; and the sitemap lists only what a visitor can open.
 */
class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = [
        'name' => 'Bilal Ahmed',
        'email' => 'bilal@example.test',
        'company' => 'Karachi Textiles',
        'message' => 'How does the trial work for a company with forty employees?',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('contact:127.0.0.1');
    }

    // ---- W5: home and the switch -------------------------------------------------------------

    public function test_the_root_is_the_home_page_with_the_tagline_the_plans_and_the_residency_sentence(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(SiteSetting::DEFAULTS['tagline'])
            ->assertSee(config('site.residency'))
            ->assertSee(Plans::label('business'))
            ->assertSee(route('signup'), false);
    }

    public function test_switching_the_site_off_restores_the_redirect_and_hides_every_page(): void
    {
        config()->set('site.enabled', false);

        $this->get('/')->assertRedirect('/admin');

        foreach (['/pricing', '/features', '/contact', '/sitemap.xml'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_a_tagline_typed_in_the_platform_panel_replaces_the_default(): void
    {
        SiteSetting::put('tagline', 'Payroll without the spreadsheet.');

        $this->get('/')->assertSee('Payroll without the spreadsheet.')->assertDontSee(SiteSetting::DEFAULTS['tagline']);
    }

    // ---- W2: pricing ---------------------------------------------------------------------------

    public function test_the_pricing_page_shows_every_plan_every_add_on_and_the_residency_sentence(): void
    {
        $response = $this->get('/pricing')->assertOk()->assertSee(config('site.residency'));

        foreach (Plans::all() as $key => $plan) {
            $response->assertSee($plan['label'])->assertSee('PKR '.number_format(Plans::price($key, 'PKR')));
        }

        foreach (Plans::addOns() as $key => $addOn) {
            $response->assertSee($addOn['label'])->assertSee('PKR '.number_format(Plans::price($key, 'PKR')));
        }

        $response->assertSee((string) Plans::minimumSeats())->assertSee(Plans::trialDays().'-day trial');
    }

    public function test_the_pricing_page_switches_currency_and_refuses_one_it_does_not_price(): void
    {
        $this->get('/pricing?currency=usd')->assertOk()->assertSee('USD '.number_format(Plans::price('starter', 'USD')));

        $this->get('/pricing?currency=XXX')->assertOk()->assertSee('PKR '.number_format(Plans::price('starter', 'PKR')));
    }

    // ---- W3: features --------------------------------------------------------------------------

    public function test_the_features_page_lists_every_module_a_plan_sells_and_none_it_does_not(): void
    {
        $response = $this->get('/features')->assertOk();

        foreach (Plans::modules('business') as $module) {
            $response->assertSee(Modules::label($module));
        }

        $response->assertDontSee(Modules::label('core'));
    }

    public function test_a_feature_page_names_its_plan_and_a_module_nobody_sells_is_not_a_page(): void
    {
        $this->get('/features/payroll')
            ->assertOk()
            ->assertSee(Modules::label('payroll'))
            ->assertSee(Plans::label('growth'));

        $this->get('/features/core')->assertNotFound();
        $this->get('/features/nonsense')->assertNotFound();
    }

    // ---- W1: hand-written pages ----------------------------------------------------------------

    public function test_a_published_page_renders_its_markdown_and_a_draft_is_not_found(): void
    {
        SitePage::query()->create(['title' => 'Privacy policy', 'body' => "## What we keep\n\nOnly what the payroll needs.", 'is_published' => true]);
        SitePage::query()->create(['title' => 'Terms', 'body' => 'Not yet.', 'is_published' => false]);

        $this->get('/p/privacy-policy')
            ->assertOk()
            ->assertSee('<h2>What we keep</h2>', false)
            ->assertSee('Only what the payroll needs.');

        $this->get('/p/terms')->assertNotFound();

        // The footer links the published page and not the draft, on every page.
        $this->get('/pricing')->assertSee('Privacy policy')->assertDontSee('>Terms<', false);
    }

    public function test_html_in_a_page_body_is_stripped_rather_than_rendered(): void
    {
        SitePage::query()->create(['title' => 'About', 'body' => 'Hello <script>alert(1)</script> there.', 'is_published' => true]);

        $this->get('/p/about')->assertOk()->assertDontSee('<script>', false);
    }

    // ---- W4: contact ---------------------------------------------------------------------------

    public function test_a_message_is_stored_and_every_active_super_admin_is_told(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['is_super_admin' => true, 'status' => 1]);
        $retired = User::factory()->create(['is_super_admin' => true, 'status' => 0]);
        $ordinary = User::factory()->create(['is_super_admin' => false, 'status' => 1]);

        $this->post('/contact', self::MESSAGE)->assertOk()->assertSee('Thank you');

        $this->assertDatabaseHas('contact_messages', ['email' => 'bilal@example.test', 'status' => ContactMessage::STATUS_NEW]);

        Notification::assertSentTo($admin, ContactMessageReceived::class);
        Notification::assertNotSentTo($retired, ContactMessageReceived::class);
        Notification::assertNotSentTo($ordinary, ContactMessageReceived::class);
    }

    public function test_a_honeypot_hit_answers_like_a_success_and_stores_nothing(): void
    {
        Notification::fake();

        $this->post('/contact', [...self::MESSAGE, 'website' => 'http://spam.example'])->assertOk()->assertSee('Thank you');

        $this->assertDatabaseCount('contact_messages', 0);
        Notification::assertNothingSent();
    }

    public function test_the_per_address_limit_is_silent(): void
    {
        Notification::fake();

        $limit = (int) config('site.contact.per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->post('/contact', [...self::MESSAGE, 'email' => "person{$i}@example.test"])->assertOk();
        }

        $this->post('/contact', [...self::MESSAGE, 'email' => 'late@example.test'])->assertOk()->assertSee('Thank you');

        $this->assertDatabaseCount('contact_messages', $limit);
        $this->assertDatabaseMissing('contact_messages', ['email' => 'late@example.test']);
    }

    public function test_validation_errors_come_back_to_the_form(): void
    {
        $this->from('/contact')
            ->post('/contact', [...self::MESSAGE, 'email' => 'not-an-email', 'message' => 'short'])
            ->assertRedirect('/contact')
            ->assertSessionHasErrors(['email', 'message']);

        $this->assertDatabaseCount('contact_messages', 0);
    }

    public function test_a_super_admin_marks_a_message_handled_in_the_platform_panel(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $message = ContactMessage::query()->create([...self::MESSAGE, 'ip_address' => '127.0.0.1']);

        $this->actingAs($admin)->get('/platform/contact-messages')->assertOk()->assertSee('Bilal Ahmed');

        Filament::setCurrentPanel(Filament::getPanel('platform'));
        Filament::bootCurrentPanel();

        Livewire::actingAs($admin)
            ->test(ListContactMessages::class)
            ->callTableAction('handled', $message);

        $this->assertSame(ContactMessage::STATUS_HANDLED, $message->fresh()->status);
        $this->assertSame($admin->getKey(), $message->fresh()->handled_by);
    }

    // ---- W5: SEO -------------------------------------------------------------------------------

    public function test_the_sitemap_lists_the_generated_pages_and_published_pages_only(): void
    {
        SitePage::query()->create(['title' => 'Privacy', 'body' => 'x', 'is_published' => true]);
        SitePage::query()->create(['title' => 'Draft', 'body' => 'x', 'is_published' => false]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee(route('site.pricing'), false)
            ->assertSee(route('site.feature', ['module' => 'payroll']), false)
            ->assertSee(route('site.page', ['slug' => 'privacy']), false)
            ->assertDontSee(route('site.page', ['slug' => 'draft']), false);
    }

    public function test_robots_allows_the_site_and_keeps_crawlers_out_of_the_panels(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /admin')
            ->assertSee('Disallow: /platform')
            ->assertSee('Sitemap: '.route('site.sitemap'));
    }

    public function test_every_public_page_is_indexable_and_carries_a_description(): void
    {
        foreach (['/', '/pricing', '/features', '/features/payroll', '/contact'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('<meta name="robots" content="index, follow">', false)
                ->assertSee('<meta name="description"', false)
                ->assertSee('<link rel="canonical"', false);
        }
    }
}
