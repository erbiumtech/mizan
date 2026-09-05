<?php

namespace App\Providers\Filament;

use App\Modules\Core\CorePlatformPlugin;
use App\Modules\Core\Filament\Pages\Auth\EditProfile;
use App\Modules\Core\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Where the installation is administered, rather than a company.
 *
 * The admin panel is declared with tenancy, so every route there is
 * `/admin/{company}/…`. That is right for a company's own administration and wrong for
 * creating companies, granting licences and appointing administrators: to do any of those
 * you first had to pick an unrelated company, and while you did, its database was the
 * connected one, its licences decided the sidebar, and spatie's permission team was that
 * company. This panel has no company, so none of that applies.
 *
 * What follows from having no tenant, and is the rule for everything registered here:
 * there is no tenant *database connection*. Only landlord-backed resources can live on
 * this panel — see CorePlatformPlugin, and the test that enforces it.
 *
 * Same guard and the same session as the admin panel, so a super admin signed into one is
 * signed into the other and "open this company" is an ordinary link into
 * `/admin/{slug}`. The separation is of context, not of identity.
 */
class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id(User::PLATFORM_PANEL)
            ->path('platform')
            /*
             * Pages use the whole window, rather than being capped at 1280px and centred.
             *
             * Filament's default is Width::SevenExtraLarge, which on a large display leaves the content in
             * a column down the middle with the rest empty — and the screens here are the ones that most
             * want the room: a statement with a comparison column, a register of transactions, a table of
             * twenty payroll figures. Width::Full lifts the cap (`:is(.fi-main).fi-width-full` is a
             * max-width of 100%), so the width is decided by the content and the rail beside it.
             *
             * A page that wants a narrower measure can still say so — this is the panel's default, not a
             * rule — and Filament's own sections and forms keep their internal widths.
             */
            ->maxContentWidth(Width::Full)
            // No ->tenant(): that is the entire point. And so no tenant menu and no
            // tenant registration — a company is created here as an ordinary record.
            ->viteTheme('resources/css/filament/admin/theme.css')
            // Its own entrance. The session is shared either way, so this is about the
            // two audiences never seeing each other's front door.
            ->login()
            // Everyone who can sign in here is a super admin, so the second factor is required of all of
            // them. Same provider as the admin panel; see AdminPanelProvider for the reasoning.
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)
            ->profile(EditProfile::class)
            ->brandName('ErbiumTech Platform')
            ->brandLogo(asset('images/logo.png'))
            ->darkModeBrandLogo(asset('images/logo-dark.png'))
            ->brandLogoHeight('3rem')
            ->favicon(asset('images/favicon.png'))
            // Deliberately not the admin panel's green: the whole value of a separate
            // panel is knowing which one you are in without reading the URL.
            ->colors([
                'primary' => Color::Indigo,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'gray' => Color::Slate,
            ])
            ->globalSearch(false)
            // Bell icon in the topbar. Echo (config/filament.php) pushes new ones
            // instantly; polling is just the fallback if a socket drops.
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            // Core only, and only the platform half of it. Modules::plugins() is
            // per-company licensing, which has no meaning without a company.
            ->plugins([
                new CorePlatformPlugin,
            ])
            ->pages([])
            ->widgets([])
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => view('filament.partials.impersonation-banner')->render(),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Blade::render('@livewire(\App\Filament\Livewire\CommandPalette::class)'),
            )
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
