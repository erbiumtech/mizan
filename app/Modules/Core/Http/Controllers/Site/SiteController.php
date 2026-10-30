<?php

namespace App\Modules\Core\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\ContactMessage;
use App\Modules\Core\Models\SitePage;
use App\Modules\Core\Models\SiteSetting;
use App\Modules\Core\Models\User;
use App\Notifications\ContactMessageReceived;
use App\Support\Modules;
use App\Support\Plans;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The public site — docs/company-website-plan.md.
 *
 * One page per method, each built as a plain array for a Blade view, like the careers and status
 * pages. Nothing here is written by hand except what SiteSetting and SitePage hold: the plans are
 * config/plans.php and the feature pages are the module manifests, so the site cannot say something
 * the product does not sell.
 *
 * With `site.enabled` off every route here is 404 and `/` is the old redirect to the admin panel.
 */
class SiteController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) config('site.enabled', true), 404);
    }

    public function home(): Response
    {
        return $this->page('site.home', [
            'title' => SiteSetting::get('tagline'),
            'description' => Str::limit((string) SiteSetting::get('intro'), 155, ''),
            'plans' => $this->plans(config('site.default_currency', 'PKR')),
            'currency' => config('site.default_currency', 'PKR'),
            'modules' => array_slice($this->soldModules(), 0, 6, true),
        ]);
    }

    public function pricing(Request $request): Response
    {
        $currencies = Plans::currencies();
        $currency = strtoupper((string) $request->query('currency', config('site.default_currency', 'PKR')));

        if (! in_array($currency, $currencies, true)) {
            $currency = $currencies[0] ?? 'PKR';
        }

        return $this->page('site.pricing', [
            'title' => 'Pricing',
            'description' => 'Per employee per month, every plan, a trial that needs no card. '.config('site.residency'),
            'currency' => $currency,
            'currencies' => $currencies,
            'plans' => $this->plans($currency),
            'addOns' => $this->addOns($currency),
            'minimumSeats' => Plans::minimumSeats(),
            'trialDays' => Plans::trialDays(),
            'trialPlan' => Plans::label(Plans::trialPlan()),
        ]);
    }

    public function features(): Response
    {
        $groups = [];

        foreach ($this->soldModules() as $key => $module) {
            $groups[$module['plan_label']][$key] = $module;
        }

        return $this->page('site.features', [
            'title' => 'Features',
            'description' => 'Every module, and the plan that includes it.',
            'groups' => $groups,
        ]);
    }

    public function feature(string $module): Response
    {
        $sold = $this->soldModules();

        abort_unless(isset($sold[$module]), 404);

        $entry = $sold[$module];

        return $this->page('site.feature', [
            'title' => $entry['label'],
            'description' => $entry['description'],
            'module' => $entry,
            'requires' => array_map(fn (string $key): array => [
                'key' => $key,
                'label' => Modules::label($key),
                'sold' => isset($sold[$key]),
            ], $entry['requires']),
        ]);
    }

    public function show(string $slug): Response
    {
        $page = SitePage::query()->published()->where('slug', $slug)->first();

        abort_unless($page, 404);

        return $this->page('site.page', [
            'title' => $page->title,
            'description' => $page->meta_description ?: Str::limit(strip_tags((string) $page->html()), 155, ''),
            'page' => $page,
        ]);
    }

    public function contact(): Response
    {
        return $this->page('site.contact', [
            'title' => 'Contact',
            'description' => 'Ask a question about the product, the price or the trial.',
        ]);
    }

    /**
     * The signup door's rule 3, at the other public form: the honeypot and the silent per-address
     * limit both answer with the same "thank you" page as a real message, having stored nothing.
     */
    public function send(Request $request): Response
    {
        $key = 'contact:'.$request->ip();

        if (filled($request->input('website'))
            || RateLimiter::tooManyAttempts($key, (int) config('site.contact.per_minute', 3))) {
            return $this->sent();
        }

        RateLimiter::hit($key);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
        ]);

        $message = ContactMessage::query()->create([
            ...$data,
            'email' => Str::lower(trim($data['email'])),
            'ip_address' => $request->ip(),
        ]);

        $admins = User::query()->where('is_super_admin', true)->where('status', 1)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new ContactMessageReceived($message));
        }

        return $this->sent();
    }

    public function sitemap(): Response
    {
        $urls = [
            route('site.home'),
            route('site.pricing'),
            route('site.features'),
            route('site.contact'),
        ];

        foreach (array_keys($this->soldModules()) as $key) {
            $urls[] = route('site.feature', ['module' => $key]);
        }

        foreach (SitePage::query()->published()->ordered()->get() as $page) {
            $urls[] = $page->url();
        }

        return response()->view('site.sitemap', ['urls' => $urls])->header('Content-Type', 'application/xml');
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /platform',
            'Disallow: /signup/verify',
            'Disallow: /verify',
            'Sitemap: '.route('site.sitemap'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain']);
    }

    private function sent(): Response
    {
        return $this->page('site.contact-sent', [
            'title' => 'Thank you',
            'description' => 'Your message has been received.',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        return response()->view($view, [
            ...$data,
            'settings' => [
                'tagline' => SiteSetting::get('tagline'),
                'intro' => SiteSetting::get('intro'),
                'contact_email' => SiteSetting::get('contact_email'),
                'contact_phone' => SiteSetting::get('contact_phone'),
                'address' => SiteSetting::get('address'),
                'footer' => SiteSetting::get('footer'),
            ],
            'pages' => SitePage::query()->published()->ordered()->get(['slug', 'title']),
            'residency' => (string) config('site.residency'),
        ]);
    }

    /**
     * The plans as the pricing page shows them, in the file's order — cheapest first — with the
     * modules each one adds to the plan it includes, so the table reads as steps rather than three
     * overlapping lists.
     *
     * @return array<string, array<string, mixed>>
     */
    private function plans(string $currency): array
    {
        $plans = [];

        foreach (Plans::all() as $key => $definition) {
            $plans[$key] = [
                'key' => $key,
                'label' => $definition['label'] ?? $key,
                'description' => $definition['description'] ?? '',
                'includes' => isset($definition['includes']) ? Plans::label($definition['includes']) : null,
                'price' => Plans::price($key, $currency),
                'custom_domain' => (bool) ($definition['custom_domain'] ?? false),
                'modules' => array_map(fn (string $module): string => Modules::label($module), array_values($definition['modules'] ?? [])),
                'is_trial' => Plans::trialPlan() === $key,
            ];
        }

        return $plans;
    }

    /** @return array<string, array<string, mixed>> */
    private function addOns(string $currency): array
    {
        $addOns = [];

        foreach (Plans::addOns() as $key => $definition) {
            $addOns[$key] = [
                'key' => $key,
                'label' => $definition['label'] ?? $key,
                'requires_plan' => Plans::label($definition['requires_plan'] ?? null),
                'price' => Plans::price($key, $currency),
                'modules' => array_map(fn (string $module): string => Modules::label($module), array_values($definition['modules'] ?? [])),
            ];
        }

        return $addOns;
    }

    /**
     * Every module some plan or add-on sells, keyed by module, each with the cheapest plan that
     * includes it. Core and anything in no plan are not pages: the site describes what is for sale.
     *
     * @return array<string, array{key: string, label: string, description: string, requires: array<int, string>, plan: string, plan_label: string, sort: int}>
     */
    private function soldModules(): array
    {
        $registry = Modules::registry();
        $sold = [];
        $order = 0;

        foreach (Plans::all() as $plan => $definition) {
            foreach (Plans::modules($plan) ?? [] as $module) {
                if (isset($sold[$module]) || ! isset($registry[$module])) {
                    continue;
                }

                $sold[$module] = $this->moduleEntry($module, $registry[$module], $plan, $definition['label'] ?? $plan, $order++);
            }
        }

        foreach (Plans::addOns() as $addOn => $definition) {
            foreach ($definition['modules'] ?? [] as $module) {
                if (isset($sold[$module]) || ! isset($registry[$module])) {
                    continue;
                }

                $sold[$module] = $this->moduleEntry($module, $registry[$module], $addOn, ($definition['label'] ?? $addOn).' add-on', $order++);
            }
        }

        return $sold;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{key: string, label: string, description: string, requires: array<int, string>, plan: string, plan_label: string, sort: int}
     */
    private function moduleEntry(string $key, array $manifest, string $plan, string $planLabel, int $sort): array
    {
        return [
            'key' => $key,
            'label' => (string) ($manifest['label'] ?? $key),
            'description' => (string) ($manifest['description'] ?? ''),
            'requires' => array_values(array_diff((array) ($manifest['requires'] ?? []), ['core'])),
            'plan' => $plan,
            'plan_label' => $planLabel,
            'sort' => $sort,
        ];
    }
}
