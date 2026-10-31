<?php

namespace App\Http\Controllers;

use App\Modules\Core\Models\Company;
use App\Providers\Filament\AdminPanelProvider;
use App\Support\CustomDomains;
use App\Support\Locales;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/**
 * The panel as an installable app — docs/desktop-mobile-plan.md M1 (and D1, which is the same artifact).
 *
 * Three things a browser asks for before it offers "install": a manifest, a service worker at the root
 * scope, and a page to show when the network is gone. The manifest wears the company — its name, its
 * panel as the start URL — because the icon on a phone's home screen is the company's, not ours; it is
 * chosen by slug, or by host on a custom domain, so an employee of two companies gets two installs.
 * Icons stay the shipped mark: the company's logo lives behind the access-checked /files route, which
 * an installer fetching without cookies cannot read, and a broken icon is worse than a neutral one.
 */
class PwaController extends Controller
{
    public function manifest(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $panel = $company ? '/admin/'.$company->slug : '/admin';
        $name = $company?->name ?? config('app.name');

        return response()->json([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'description' => __('Payroll, books and people — :company', ['company' => $name]),
            'id' => $panel,
            'start_url' => $panel,
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => AdminPanelProvider::BRAND_GREEN,
            'lang' => app()->getLocale(),
            'dir' => Locales::isRtl(app()->getLocale()) ? 'rtl' : 'ltr',
            'icons' => [
                ['src' => asset('images/logo-mark.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('images/logo-mark.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * Served from the root, not from the Vite build: a worker's scope is its URL's directory, and a hashed
     * file under /build could only ever control /build. `no-cache` so a changed worker is picked up on the
     * next visit rather than after the browser's 24-hour ceiling.
     */
    public function serviceWorker(): Response
    {
        return response(File::get(resource_path('pwa/sw.js')), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function offline(): View
    {
        return view('pwa.offline');
    }

    private function company(Request $request): ?Company
    {
        if ($host = CustomDomains::hostCompany()) {
            return $host;
        }

        $slug = (string) $request->query('company', '');

        return $slug === '' ? null : Company::query()->where('slug', $slug)->first();
    }
}
