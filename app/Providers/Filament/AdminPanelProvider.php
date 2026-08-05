<?php

namespace App\Providers\Filament;

use App\Modules\Core\Filament\Pages\Auth\EditProfile;
use App\Modules\Core\Models\Company;
use App\Support\Modules;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Brand colours sampled from the ErbiumTech logo (public/images/logo.png):
     * the three diagonal slashes, dark green through to lime.
     */
    public const string BRAND_GREEN = '#3E894A';

    public const string BRAND_GREEN_MID = '#91BD55';

    public const string BRAND_LIME = '#D3DA54';

    /**
     * Primary ramp built by hand around BRAND_GREEN rather than via
     * Color::hex(), which normalises lightness per shade and would leave the
     * actual logo green absent from the palette. Shade 600 is what Filament
     * paints buttons and active nav with, so the brand colour sits there.
     *
     * @var array<int, string>
     */
    protected const array BRAND_GREEN_SHADES = [
        50 => '#F7FAF8',
        100 => '#E8F1E9',
        200 => '#CDE0D0',
        300 => '#A8CAAE',
        400 => '#83B38B',
        500 => '#619E6B',
        600 => self::BRAND_GREEN,
        700 => '#367741',
        800 => '#2E6537',
        900 => '#26522E',
        950 => '#1C3E23',
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->tenant(Company::class, slugAttribute: 'slug')
            // Company switcher for users who belong to more than one company.
            ->tenantMenu()
            // No tenant registration. Creating a company provisions a database,
            // migrates it and seeds its roles — installation-level work, which now
            // happens on the platform panel where there is no company in scope to
            // confuse it with. Two routes to the same act meant two places to keep the
            // super-admin check.
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            // Self-service password change (user menu → Change Password).
            // Simple layout: the profile route sits outside the tenant prefix.
            ->profile(EditProfile::class)
            ->brandName('ErbiumTech')
            ->brandLogo(asset('images/logo.png'))
            ->darkModeBrandLogo(asset('images/logo-dark.png'))
            ->brandLogoHeight('3rem')
            ->favicon(asset('images/favicon.png'))
            // Sampled from the ErbiumTech logo: the dark green slash drives the
            // primary ramp, the mid green and lime are used as accents.
            ->colors([
                'primary' => self::BRAND_GREEN_SHADES,
                'success' => Color::hex(self::BRAND_GREEN_MID),
                'warning' => Color::hex(self::BRAND_LIME),
                'gray' => Color::Slate,
            ])
            // Disabled in favour of the ⌘K command palette, which is a superset:
            // it searches records through the same resource global-search hooks,
            // and also finds resources, pages and commands. Resources keep their
            // getGloballySearchableAttributes() — canGloballySearch() does not
            // consult this setting, so the palette still finds records.
            ->globalSearch(false)
            // Every resource, page and widget now belongs to a module and is
            // registered by that module's plugin — there is no app-level discovery
            // left. Registration is unconditional regardless of licence state; see
            // any Plugin class for why that cannot be otherwise.
            ->plugins(Modules::plugins())
            ->pages([
                Dashboard::class,
            ])
            ->widgets([])
            // Impersonation banner, above everything else on the page. PAGE_START
            // would put it inside the content area; this sits at the top of the
            // body so it is present on every panel page including the ones that
            // scroll.
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => view('filament.partials.impersonation-banner')->render(),
            )
            // ⌘K command palette (rendered on every panel page).
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Blade::render('@livewire(\App\Filament\Livewire\CommandPalette::class)'),
            )
            // One search box, not two. Filament's global search field is turned
            // off above and this trigger takes its place in the topbar, opening
            // the ⌘K palette instead — which searches the same records (via each
            // resource's getGlobalSearchResults) plus resources, pages and
            // commands. GLOBAL_SEARCH_BEFORE renders even when global search is
            // disabled, so this lands exactly where the old field sat: inside
            // fi-topbar-end, ahead of the notifications and user menu.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => view('filament.partials.command-palette-trigger')->render(),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
